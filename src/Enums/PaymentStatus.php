<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    /**
     * Statuses from which no further transition is allowed.
     *
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return [self::Paid, self::Failed, self::Cancelled];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }
}
