<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Exceptions;

use RuntimeException;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;

class InvalidStateTransitionException extends RuntimeException
{
    public static function make(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self(sprintf(
            'Cannot transition payment from "%s" to "%s".',
            $from->value,
            $to->value
        ));
    }
}
