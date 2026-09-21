<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use VendorName\LaravelPaymentReconciliation\Exceptions\InvalidAmountException;

/**
 * Stores monetary amounts strictly as integers (minor currency units,
 * e.g. cents). Floats are rejected at the point of assignment so a
 * rounding/precision bug can never enter the public API.
 *
 * @implements CastsAttributes<int|null, mixed>
 */
class MoneyCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        // Defensive: a raw DB value should always be numeric here, but if
        // the column was ever corrupted by something outside this cast
        // (a manual UPDATE, a bad migration), returning null is safer
        // than (int) casting garbage into a false-looking zero amount.
        return is_numeric($value) ? (int) $value : null;
    }

    public function set($model, string $key, $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value)) {
            throw InvalidAmountException::floatsNotAllowed();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }

        throw InvalidAmountException::mustBeInteger($value);
    }
}
