<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use VendorName\LaravelPaymentReconciliation\Casts\MoneyCast;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;

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

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('payment-reconciliation.table_names.payments', 'payments'));
    }

    /**
     * The host application's model this payment belongs to (an order,
     * a subscription, ...), when one exists as an Eloquent model.
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
     * @param  array<string, mixed>  $context
     */
    public function transitionTo(PaymentStatus $to, array $context = []): self
    {
        return app(PaymentService::class)->transitionTo($this, $to, $context);
    }
}
