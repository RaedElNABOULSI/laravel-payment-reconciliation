<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Reconciliation;

enum ReconciliationResultType: string
{
    /** Local and provider state agree; nothing changed. */
    case Matched = 'matched';

    /** An unknown payment was safely resolved to paid/failed. */
    case Resolved = 'resolved';

    /** Local and provider state disagree; nothing was changed automatically. */
    case Mismatch = 'mismatch';

    /** The payment is still unknown; the provider has no definitive answer yet. */
    case StillUnknown = 'still_unknown';

    /** The provider could not be reached; nothing was changed. */
    case ProviderUnavailable = 'provider_unavailable';
}
