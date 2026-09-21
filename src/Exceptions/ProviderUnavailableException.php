<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by a PaymentProvider adapter when it cannot determine a
 * payment's status (network timeout, provider outage, ...). Callers
 * must treat this as "unknown", never as "failed".
 */
class ProviderUnavailableException extends RuntimeException
{
    public static function make(string $provider, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Provider "%s" is unavailable or the request timed out.', $provider),
            0,
            $previous
        );
    }
}
