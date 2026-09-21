<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Events;

use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Reconciliation\ReconciliationResult;

/**
 * Fired whenever a payment is reconciled with its provider, regardless
 * of whether the outcome was a match, a resolution, or a mismatch.
 */
class PaymentReconciled
{
    public function __construct(
        public readonly Payment $payment,
        public readonly ReconciliationResult $result,
    ) {}
}
