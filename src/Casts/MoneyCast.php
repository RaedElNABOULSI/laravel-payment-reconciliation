<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Raedev\LaravelPaymentReconciliation\Exceptions\InvalidAmountException;

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
            $amount = $value;
        } elseif (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            // A single optional leading '-' followed by digits only - not
            // ltrim(), which strips every leading '-' and would let a
            // malformed string like "--5" pass validation as "5" while
            // still being (int) cast from the original "--5" (which PHP
            // parses as 0, since '-' is not a valid digit after a sign).
            $amount = (int) $value;
        } else {
            throw InvalidAmountException::mustBeInteger($value);
        }

        if ($amount < 0) {
            // The `amount` column is unsignedBigInteger - reject negative
            // values here with a clear message instead of letting them
            // reach the database, where behavior is driver-dependent
            // (a raw QueryException on MySQL, silent persistence on
            // SQLite, which doesn't enforce "unsigned" at all).
            throw InvalidAmountException::mustNotBeNegative($amount);
        }

        return $amount;
    }
}
