<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Events;

class WebhookDuplicateDetected
{
    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
    ) {}
}
