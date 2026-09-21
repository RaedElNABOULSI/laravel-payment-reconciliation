<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Webhooks;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Raedev\LaravelPaymentReconciliation\Contracts\PaymentProvider;
use Raedev\LaravelPaymentReconciliation\Contracts\ProviderPaymentStatus;
use Raedev\LaravelPaymentReconciliation\Contracts\ProviderWebhookPayload;
use Raedev\LaravelPaymentReconciliation\Enums\PaymentStatus;
use Raedev\LaravelPaymentReconciliation\Events\PaymentMismatchDetected;
use Raedev\LaravelPaymentReconciliation\Events\WebhookDuplicateDetected;
use Raedev\LaravelPaymentReconciliation\Exceptions\InvalidStateTransitionException;
use Raedev\LaravelPaymentReconciliation\Exceptions\PaymentIntegrityException;
use Raedev\LaravelPaymentReconciliation\Exceptions\UnknownProviderTransactionException;
use Raedev\LaravelPaymentReconciliation\Exceptions\WebhookVerificationException;
use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Models\WebhookEvent;
use Raedev\LaravelPaymentReconciliation\Reconciliation\ReconciliationResult;
use Raedev\LaravelPaymentReconciliation\Services\PaymentService;
use Raedev\LaravelPaymentReconciliation\Support\DetectsUniqueConstraintViolations;

/**
 * Safely applies incoming provider webhooks.
 *
 * Duplicate deliveries are rejected by the unique (provider, event_id)
 * database constraint, not by a check-then-act read - the insert
 * attempt IS the concurrency check. Everything that follows (locating
 * and locking the payment, applying the transition) happens inside the
 * same database transaction as that insert.
 */
class WebhookProcessor
{
    use DetectsUniqueConstraintViolations;

    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     * @param  string|null  $rawBody  The exact, unparsed request body, when available - see
     *                                PaymentProvider::verifyWebhook() for why this matters.
     *
     * @throws WebhookVerificationException
     * @throws UnknownProviderTransactionException
     */
    public function process(array $payload, array $headers, PaymentProvider $provider, ?string $rawBody = null): WebhookResult
    {
        if (! $provider->verifyWebhook($payload, $headers, $rawBody)) {
            throw WebhookVerificationException::invalidSignature($provider->getName());
        }

        $parsed = $provider->parseWebhookPayload($payload);

        try {
            return DB::transaction(function () use ($parsed, $provider) {
                $webhookEvent = WebhookEvent::create([
                    'provider' => $provider->getName(),
                    'event_id' => $parsed->eventId,
                    'payload' => $parsed->raw,
                ]);

                $payment = Payment::query()
                    ->where('provider', $provider->getName())
                    ->where('provider_transaction_id', $parsed->providerTransactionId)
                    ->lockForUpdate()
                    ->first();

                if ($payment === null) {
                    throw UnknownProviderTransactionException::make($provider->getName(), $parsed->providerTransactionId);
                }

                $result = $this->applyOutcome($payment, $parsed);

                $webhookEvent->update(['payment_id' => $result->payment?->id]);

                return $result;
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            event(new WebhookDuplicateDetected($provider->getName(), $parsed->eventId));

            return new WebhookResult(WebhookResultType::Duplicate);
        }
    }

    private function applyOutcome(Payment $payment, ProviderWebhookPayload $parsed): WebhookResult
    {
        $providerStatus = new ProviderPaymentStatus(
            status: $parsed->status,
            providerTransactionId: $parsed->providerTransactionId,
            amount: $parsed->amount,
            currency: $parsed->currency,
            raw: $parsed->raw,
        );

        try {
            if ($parsed->status === PaymentStatus::Paid) {
                $payment = $this->paymentService->markPaid(
                    $payment,
                    $parsed->amount,
                    $parsed->currency,
                    $parsed->providerTransactionId,
                );
            } else {
                $payment = $this->paymentService->transitionTo($payment, $parsed->status, [
                    'provider_status' => $parsed->status->value,
                    'provider_transaction_id' => $parsed->providerTransactionId,
                ]);
            }

            return new WebhookResult(WebhookResultType::Processed, $payment, $providerStatus);
        } catch (PaymentIntegrityException|InvalidStateTransitionException $e) {
            $payment->forceFill(['provider_status' => $parsed->status->value])->save();

            event(new PaymentMismatchDetected(
                $payment,
                ReconciliationResult::mismatch($payment, $providerStatus, [$e->getMessage()])
            ));

            return new WebhookResult(WebhookResultType::Rejected, $payment, $providerStatus);
        }
    }
}
