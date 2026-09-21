<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Events;

use Raedev\LaravelPaymentReconciliation\Models\Payment;

class PaymentProcessing
{
    public function __construct(public readonly Payment $payment) {}
}
