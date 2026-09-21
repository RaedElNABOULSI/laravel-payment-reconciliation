<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Services;

use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;

/**
 * Stateless validator for the payment lifecycle. Holds the single
 * source of truth for which transitions are allowed so it can never be
 * bypassed by application code changing a status column directly
 * through the state machine.
 */
class PaymentStateMachine
{
    /**
     * @var array<string, array<int, PaymentStatus>>
     */
    private const TRANSITIONS = [
        'pending' => [PaymentStatus::Processing, PaymentStatus::Cancelled],
        'processing' => [PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Unknown],
        'unknown' => [PaymentStatus::Paid, PaymentStatus::Failed],
        'paid' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    public function can(PaymentStatus $from, PaymentStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * @return array<int, PaymentStatus>
     */
    public function allowedFrom(PaymentStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
