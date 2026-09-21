<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Webhooks;

use Raedev\LaravelPaymentReconciliation\Contracts\ProviderPaymentStatus;
use Raedev\LaravelPaymentReconciliation\Models\Payment;

final class WebhookResult
{
    public function __construct(
        public readonly WebhookResultType $type,
        public readonly ?Payment $payment = null,
        public readonly ?ProviderPaymentStatus $providerStatus = null,
    ) {}
}
