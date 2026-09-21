<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Support;

use Illuminate\Database\QueryException;

/**
 * Detects whether a QueryException was caused by a unique constraint
 * violation, across the database drivers Laravel supports, without
 * depending on Illuminate\Database\UniqueConstraintViolationException
 * (only available from Laravel 11 onward) so the package keeps working
 * on Laravel 10.
 */
trait DetectsUniqueConstraintViolations
{
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        // 23505 is Postgres's unique_violation code specifically - unlike
        // MySQL/SQLite, Postgres also has distinct codes for not-null
        // (23502) and foreign key (23503) violations, so this code alone
        // is unambiguous. MySQL and SQLite both report every constraint
        // violation (unique, not-null, foreign key) under the same
        // generic 23000, so that code cannot be used here without also
        // misclassifying not-null/FK failures as duplicates.
        if ($e->getCode() === '23505') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
