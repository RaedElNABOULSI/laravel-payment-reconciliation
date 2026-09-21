<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of a single processed provider webhook delivery, used to
 * guarantee idempotency via a unique (provider, event_id) constraint.
 *
 * @property string $provider
 * @property string $event_id
 * @property string|null $payment_id
 * @property array<string, mixed> $payload
 */
class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('payment-reconciliation.table_names.webhook_events', 'webhook_events'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
