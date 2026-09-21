<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Exceptions;

use RuntimeException;

class PaymentIntegrityException extends RuntimeException
{
    public static function amountMismatch(int $expected, int $actual): self
    {
        return new self(sprintf(
            'Payment amount mismatch: expected %d, provider reported %d.',
            $expected,
            $actual
        ));
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Payment currency mismatch: expected %s, provider reported %s.',
            $expected,
            $actual
        ));
    }
}
