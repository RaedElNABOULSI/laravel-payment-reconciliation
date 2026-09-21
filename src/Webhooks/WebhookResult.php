<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Webhooks;

use VendorName\LaravelPaymentReconciliation\Contracts\ProviderPaymentStatus;
use VendorName\LaravelPaymentReconciliation\Models\Payment;

final class WebhookResult
{
    public function __construct(
        public readonly WebhookResultType $type,
        public readonly ?Payment $payment = null,
        public readonly ?ProviderPaymentStatus $providerStatus = null,
    ) {}
}
