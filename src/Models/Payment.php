<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Raedev\LaravelPaymentReconciliation\Casts\MoneyCast;
use Raedev\LaravelPaymentReconciliation\Enums\PaymentStatus;
use Raedev\LaravelPaymentReconciliation\Services\PaymentService;

/**
 * @property string $id
 * @property string|null $payable_type
 * @property string|null $payable_id
 * @property string|null $payable_reference
 * @property string $provider
 * @property string|null $provider_transaction_id
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $provider_status
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $last_reconciled_at
 */
class Payment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    /**
     * Set directly (not via a `creating` model event) so the default
     * survives even when the host application's tests call
     * Event::fake(), which also silences Eloquent's lifecycle events.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'amount' => MoneyCast::class,
        'status' => PaymentStatus::class,
        'metadata' => 'array',
        'last_reconciled_at' => 'datetime',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $table = config('payment-reconciliation.table_names.payments', 'payments');

        $this->setTable(is_string($table) ? $table : 'payments');
    }

    /**
     * The host application's model this payment belongs to (an order,
     * a subscription, ...), when one exists as an Eloquent model.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Validate and apply a state transition, delegating to the
     * PaymentService so locking, persistence and events stay in one
     * place regardless of whether callers use the model or the service.
     *
     * Refuses `PaymentStatus::Paid` - use markPaid() instead, which can
     * verify the amount/currency before accepting the payment as paid.
     *
     * @param  array<string, mixed>  $context
     */
    public function transitionTo(PaymentStatus $to, array $context = []): self
    {
        return app(PaymentService::class)->transitionTo($this, $to, $context);
    }

    /**
     * Mark this payment paid, optionally verifying the amount/currency
     * the provider actually reported before doing so. This is the only
     * way to reach `Paid` - see PaymentService::markPaid().
     */
    public function markPaid(?int $actualAmount = null, ?string $actualCurrency = null, ?string $providerTransactionId = null): self
    {
        return app(PaymentService::class)->markPaid($this, $actualAmount, $actualCurrency, $providerTransactionId);
    }
}
