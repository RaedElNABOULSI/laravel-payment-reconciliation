<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Exceptions;

use RuntimeException;

class WebhookVerificationException extends RuntimeException
{
    public static function invalidSignature(string $provider): self
    {
        return new self(sprintf('Webhook signature verification failed for provider "%s".', $provider));
    }
}
