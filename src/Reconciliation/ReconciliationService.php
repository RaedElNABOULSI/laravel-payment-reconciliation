<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Reconciliation;

use Illuminate\Support\Facades\Log;
use VendorName\LaravelPaymentReconciliation\Contracts\PaymentProvider;
use VendorName\LaravelPaymentReconciliation\Contracts\ProviderPaymentStatus;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Events\PaymentMismatchDetected;
use VendorName\LaravelPaymentReconciliation\Events\PaymentReconciled;
use VendorName\LaravelPaymentReconciliation\Exceptions\PaymentIntegrityException;
use VendorName\LaravelPaymentReconciliation\Exceptions\ProviderUnavailableException;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;

/**
 * Compares local payment state against the provider's state and
 * decides what, if anything, is safe to change automatically.
 *
 * Only an `unknown` payment is ever auto-resolved (to paid/failed).
 * Every other disagreement is reported via a ReconciliationResult and
 * a PaymentMismatchDetected event, never silently applied - the local
 * record is not blindly overwritten just because the provider disagrees.
 */
class ReconciliationService
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function reconcile(Payment $payment, PaymentProvider $provider): ReconciliationResult
    {
        try {
            $providerStatus = $provider->getPaymentStatus($payment);
        } catch (ProviderUnavailableException $e) {
            Log::warning('Payment reconciliation skipped: provider unavailable.', [
                'payment_id' => $payment->id,
                'provider' => $provider->getName(),
                'error' => $e->getMessage(),
            ]);

            return ReconciliationResult::providerUnavailable($payment, $e->getMessage());
        }

        $result = $payment->status === PaymentStatus::Unknown
            ? $this->resolveUnknown($payment, $providerStatus)
            : $this->compare($payment, $providerStatus);

        $result->payment->forceFill(['last_reconciled_at' => now()])->save();

        event(new PaymentReconciled($result->payment, $result));

        if ($result->type === ReconciliationResultType::Mismatch) {
            event(new PaymentMismatchDetected($result->payment, $result));
        }

        return $result;
    }

    private function resolveUnknown(Payment $payment, ProviderPaymentStatus $providerStatus): ReconciliationResult
    {
        if ($providerStatus->status === PaymentStatus::Failed) {
            $resolved = $this->paymentService->transitionTo($payment, PaymentStatus::Failed);

            return ReconciliationResult::resolved($resolved, $providerStatus);
        }

        if ($providerStatus->status === PaymentStatus::Paid) {
            try {
                $resolved = $this->paymentService->markPaid(
                    $payment,
                    $providerStatus->amount,
                    $providerStatus->currency,
                    $providerStatus->providerTransactionId,
                );

                return ReconciliationResult::resolved($resolved, $providerStatus);
            } catch (PaymentIntegrityException $e) {
                // The provider says paid, but its own amount/currency
                // doesn't match what we expect - do not resolve unknown
                // to paid just because a status field agrees; report it.
                return ReconciliationResult::mismatch($payment, $providerStatus, [$e->getMessage()]);
            }
        }

        return ReconciliationResult::stillUnknown($payment, $providerStatus);
    }

    private function compare(Payment $payment, ProviderPaymentStatus $providerStatus): ReconciliationResult
    {
        $differences = [];

        if ($payment->status !== $providerStatus->status) {
            $differences[] = sprintf('status: local=%s provider=%s', $payment->status->value, $providerStatus->status->value);
        }

        if ($providerStatus->amount !== null && $providerStatus->amount !== $payment->amount) {
            $differences[] = sprintf('amount: local=%d provider=%d', $payment->amount, $providerStatus->amount);
        }

        if ($providerStatus->currency !== null && strcasecmp($providerStatus->currency, $payment->currency) !== 0) {
            $differences[] = sprintf('currency: local=%s provider=%s', $payment->currency, $providerStatus->currency);
        }

        if ($differences === []) {
            return ReconciliationResult::matched($payment, $providerStatus);
        }

        return ReconciliationResult::mismatch($payment, $providerStatus, $differences);
    }
}
