<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Webhooks;

enum WebhookResultType: string
{
    /** A new, authentic event that produced a state change (or confirmed an already-matching state). */
    case Processed = 'processed';

    /** The same (provider, event_id) was already processed; nothing was done. */
    case Duplicate = 'duplicate';

    /** An authentic, new event described a change the integrity checks or state machine rejected. */
    case Rejected = 'rejected';
}
