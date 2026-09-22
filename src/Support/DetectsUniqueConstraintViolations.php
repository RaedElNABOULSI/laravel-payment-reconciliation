<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Support;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Detects whether a QueryException was caused by a unique constraint
 * violation. Laravel itself already distinguishes this per driver (see
 * Illuminate\Database\Connection::isUniqueConstraintError() and its
 * MySQL/Postgres/SQLite/SQL Server overrides - MySQL's is keyed off
 * error code 1062, Postgres off SQLSTATE 23505, SQLite off its exact
 * "UNIQUE constraint failed" wording) and throws
 * UniqueConstraintViolationException specifically when it matches.
 * That is more precise than a message-substring check this package
 * could maintain on its own, so we defer to it entirely rather than
 * re-implementing driver-specific detection.
 */
trait DetectsUniqueConstraintViolations
{
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e instanceof UniqueConstraintViolationException;
    }
}
