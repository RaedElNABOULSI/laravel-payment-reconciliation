<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Exceptions;

use RuntimeException;

class UnknownProviderTransactionException extends RuntimeException
{
    public static function make(string $provider, string $providerTransactionId): self
    {
        return new self(sprintf(
            'No payment found for provider "%s" with transaction id "%s".',
            $provider,
            $providerTransactionId
        ));
    }
}
