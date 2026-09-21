<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Events;

use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Reconciliation\ReconciliationResult;

/**
 * Fired when local and provider state disagree (status, amount, or
 * currency) and the package deliberately did not change local state
 * automatically.
 */
class PaymentMismatchDetected
{
    public function __construct(
        public readonly Payment $payment,
        public readonly ReconciliationResult $result,
    ) {}
}
