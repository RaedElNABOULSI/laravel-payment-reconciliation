<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Contracts;

use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;

/**
 * The provider's view of a payment at a point in time, as returned by
 * PaymentProvider::getPaymentStatus().
 */
final class ProviderPaymentStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $providerTransactionId = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {}
}
