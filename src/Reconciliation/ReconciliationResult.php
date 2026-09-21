<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Reconciliation;

use Raedev\LaravelPaymentReconciliation\Contracts\ProviderPaymentStatus;
use Raedev\LaravelPaymentReconciliation\Models\Payment;

/**
 * The outcome of reconciling one payment against its provider. Carries
 * enough detail for the host application to act, log, or alert without
 * re-deriving what happened.
 */
final class ReconciliationResult
{
    /**
     * @param  array<int, string>  $differences
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly ReconciliationResultType $type,
        public readonly ?ProviderPaymentStatus $providerStatus = null,
        public readonly array $differences = [],
        public readonly ?string $message = null,
    ) {}

    public static function matched(Payment $payment, ProviderPaymentStatus $providerStatus): self
    {
        return new self($payment, ReconciliationResultType::Matched, $providerStatus);
    }

    public static function resolved(Payment $payment, ProviderPaymentStatus $providerStatus): self
    {
        return new self($payment, ReconciliationResultType::Resolved, $providerStatus);
    }

    /**
     * @param  array<int, string>  $differences
     */
    public static function mismatch(Payment $payment, ProviderPaymentStatus $providerStatus, array $differences): self
    {
        return new self($payment, ReconciliationResultType::Mismatch, $providerStatus, $differences);
    }

    public static function stillUnknown(Payment $payment, ProviderPaymentStatus $providerStatus): self
    {
        return new self($payment, ReconciliationResultType::StillUnknown, $providerStatus);
    }

    public static function providerUnavailable(Payment $payment, string $message): self
    {
        return new self($payment, ReconciliationResultType::ProviderUnavailable, null, [], $message);
    }
}
