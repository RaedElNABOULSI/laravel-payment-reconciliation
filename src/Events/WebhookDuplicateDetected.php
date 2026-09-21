<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Events;

class WebhookDuplicateDetected
{
    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
    ) {}
}
