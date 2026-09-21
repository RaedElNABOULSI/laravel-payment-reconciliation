<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Exceptions;

use InvalidArgumentException;

class InvalidAmountException extends InvalidArgumentException
{
    public static function floatsNotAllowed(): self
    {
        return new self(
            'Payment amounts must be integers representing the smallest currency unit (e.g. cents). '
            .'Floats are rejected to prevent rounding/precision errors.'
        );
    }

    public static function mustBeInteger(mixed $value): self
    {
        return new self(sprintf(
            'Payment amount must be an integer representing the smallest currency unit, got "%s".',
            get_debug_type($value)
        ));
    }
}
