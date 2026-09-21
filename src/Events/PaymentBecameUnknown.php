<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Events;

use VendorName\LaravelPaymentReconciliation\Models\Payment;

class PaymentBecameUnknown
{
    public function __construct(public readonly Payment $payment) {}
}
