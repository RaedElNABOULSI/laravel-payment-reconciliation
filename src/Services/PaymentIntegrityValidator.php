<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Services;

use VendorName\LaravelPaymentReconciliation\Exceptions\PaymentIntegrityException;
use VendorName\LaravelPaymentReconciliation\Models\Payment;

/**
 * Centralises the "never assume webhook says paid = payment is valid"
 * checks so both PaymentService and WebhookProcessor apply the same
 * rules before a payment is accepted as paid.
 */
class PaymentIntegrityValidator
{
    /**
     * @throws PaymentIntegrityException
     */
    public function assertAmountAndCurrencyMatch(Payment $payment, ?int $actualAmount, ?string $actualCurrency): void
    {
        if ($actualAmount !== null && $actualAmount !== $payment->amount) {
            throw PaymentIntegrityException::amountMismatch($payment->amount, $actualAmount);
        }

        if ($actualCurrency !== null && strcasecmp($actualCurrency, $payment->currency) !== 0) {
            throw PaymentIntegrityException::currencyMismatch($payment->currency, $actualCurrency);
        }
    }
}
