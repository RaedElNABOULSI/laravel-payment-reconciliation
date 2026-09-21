<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Events\PaymentBecameUnknown;
use VendorName\LaravelPaymentReconciliation\Events\PaymentCancelled;
use VendorName\LaravelPaymentReconciliation\Events\PaymentCreated;
use VendorName\LaravelPaymentReconciliation\Events\PaymentFailed;
use VendorName\LaravelPaymentReconciliation\Events\PaymentPaid;
use VendorName\LaravelPaymentReconciliation\Events\PaymentProcessing;
use VendorName\LaravelPaymentReconciliation\Exceptions\InvalidStateTransitionException;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Support\DetectsUniqueConstraintViolations;

/**
 * Application-facing entry point for creating payments and moving them
 * through the state machine. Every transition goes through the private
 * applyTransition(), which is the only place that locks the row,
 * validates the transition, and persists it - whether callers use
 * transitionTo(), markPaid(), or the Payment model's convenience
 * wrappers. transitionTo() refuses `Paid` specifically: that target
 * must go through markPaid() so amount/currency can be verified first,
 * which a bare status-only transition has no way to do.
 */
class PaymentService
{
    use DetectsUniqueConstraintViolations;

    public function __construct(
        private readonly PaymentStateMachine $stateMachine,
        private readonly PaymentIntegrityValidator $integrity,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Payment
    {
        $payment = Payment::create($attributes);

        event(new PaymentCreated($payment));

        return $payment;
    }

    /**
     * Create a payment, or return the existing one if an identical
     * request (same idempotency_key) was already made. Relies on the
     * unique constraint on `idempotency_key`, not a check-then-act read.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createIdempotent(array $attributes): Payment
    {
        $idempotencyKey = $attributes['idempotency_key'] ?? null;

        if ($idempotencyKey === null) {
            return $this->create($attributes);
        }

        try {
            return $this->create($attributes);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // The violation might be on (provider, provider_transaction_id)
            // rather than idempotency_key. Only treat this as "already
            // created" if a row with this exact key actually exists -
            // otherwise this was a different, real conflict and must
            // propagate rather than surface as a misleading "not found".
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Validate and apply a state transition under a row lock. Refuses
     * `Paid` - use markPaid() instead, which can verify amount/currency
     * before accepting it. This is the only path (besides markPaid)
     * that mutates a payment's status.
     *
     * @param  array<string, mixed>  $context  Additional attributes to persist alongside the status change.
     */
    public function transitionTo(Payment $payment, PaymentStatus $to, array $context = []): Payment
    {
        if ($to === PaymentStatus::Paid) {
            throw new InvalidArgumentException(
                'Cannot transition to Paid via transitionTo(); call markPaid() instead so the '
                .'amount and currency can be verified before the payment is accepted.'
            );
        }

        return $this->applyTransition($payment, $to, $context);
    }

    /**
     * Validate and apply a state transition under a row lock, without
     * the transitionTo() guard against `Paid`. Shared by transitionTo()
     * and markPaid() so both go through identical locking, validation,
     * and event-dispatch logic.
     *
     * A transition to the payment's *current* status is treated as a
     * safe no-op (context is still persisted, but the state machine is
     * not re-checked and no transition event fires) - this keeps retried
     * webhooks and repeated application calls idempotent instead of
     * raising InvalidStateTransitionException for "no-op" cases.
     *
     * @param  array<string, mixed>  $context
     */
    private function applyTransition(Payment $payment, PaymentStatus $to, array $context): Payment
    {
        return DB::transaction(function () use ($payment, $to, $context) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $to) {
                $locked->fill($context);
                $locked->save();

                return $locked;
            }

            if (! $this->stateMachine->can($locked->status, $to)) {
                throw InvalidStateTransitionException::make($locked->status, $to);
            }

            $locked->fill($context);
            $locked->status = $to;
            $locked->save();

            $this->dispatchTransitionEvent($locked, $to);

            return $locked;
        });
    }

    public function markProcessing(Payment $payment): Payment
    {
        return $this->transitionTo($payment, PaymentStatus::Processing);
    }

    public function markUnknown(Payment $payment): Payment
    {
        return $this->transitionTo($payment, PaymentStatus::Unknown);
    }

    public function markCancelled(Payment $payment): Payment
    {
        return $this->transitionTo($payment, PaymentStatus::Cancelled);
    }

    public function markFailed(Payment $payment): Payment
    {
        return $this->transitionTo($payment, PaymentStatus::Failed);
    }

    /**
     * Mark a payment paid, optionally verifying the amount/currency the
     * provider actually reported against what the payment expects
     * before doing so. Never trust "webhook says paid" alone.
     *
     * This is the only path that can reach `Paid` - transitionTo()
     * refuses that target so this check can never be bypassed by using
     * the more generic method instead.
     */
    public function markPaid(
        Payment $payment,
        ?int $actualAmount = null,
        ?string $actualCurrency = null,
        ?string $providerTransactionId = null,
    ): Payment {
        $this->integrity->assertAmountAndCurrencyMatch($payment, $actualAmount, $actualCurrency);

        $context = ['provider_status' => PaymentStatus::Paid->value];

        if ($providerTransactionId !== null) {
            $context['provider_transaction_id'] = $providerTransactionId;
        }

        return $this->applyTransition($payment, PaymentStatus::Paid, $context);
    }

    private function dispatchTransitionEvent(Payment $payment, PaymentStatus $to): void
    {
        match ($to) {
            PaymentStatus::Processing => event(new PaymentProcessing($payment)),
            PaymentStatus::Paid => event(new PaymentPaid($payment)),
            PaymentStatus::Failed => event(new PaymentFailed($payment)),
            PaymentStatus::Cancelled => event(new PaymentCancelled($payment)),
            PaymentStatus::Unknown => event(new PaymentBecameUnknown($payment)),
            PaymentStatus::Pending => null,
        };
    }
}
