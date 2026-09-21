<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Contracts;

use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;

/**
 * A provider webhook payload normalised into the identifiers and values
 * the package needs, as returned by PaymentProvider::parseWebhookPayload().
 */
final class ProviderWebhookPayload
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $providerTransactionId,
        public readonly PaymentStatus $status,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {}
}
