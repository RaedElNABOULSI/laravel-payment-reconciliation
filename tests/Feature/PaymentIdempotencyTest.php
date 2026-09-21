<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Database\QueryException;
use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Services\PaymentService;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    public function test_same_idempotency_key_is_processed_once(): void
    {
        $service = app(PaymentService::class);

        $attributes = [
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'idempotency_key' => 'order-123',
        ];

        $first = $service->createIdempotent($attributes);
        $second = $service->createIdempotent($attributes);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Payment::query()->where('idempotency_key', 'order-123')->count());
    }

    public function test_duplicate_request_with_different_amount_still_returns_original_payment(): void
    {
        $service = app(PaymentService::class);

        $first = $service->createIdempotent([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'idempotency_key' => 'order-456',
        ]);

        // A retried request for the same key must be recognised as the
        // same operation, not create a second row or mutate the first.
        $second = $service->createIdempotent([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'idempotency_key' => 'order-456',
        ]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1000, $second->fresh()->amount);
    }

    public function test_requests_without_an_idempotency_key_each_create_a_new_payment(): void
    {
        $service = app(PaymentService::class);

        $first = $service->createIdempotent(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $second = $service->createIdempotent(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);

        $this->assertFalse($first->is($second));
    }

    public function test_database_unique_constraint_protects_against_duplicate_idempotency_keys_bypassing_the_service(): void
    {
        Payment::create([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'idempotency_key' => 'raw-key',
        ]);

        $this->expectException(QueryException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => 2000,
            'currency' => 'USD',
            'idempotency_key' => 'raw-key',
        ]);
    }

    public function test_database_unique_constraint_allows_multiple_payments_without_a_provider_transaction_id(): void
    {
        $one = Payment::create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $two = Payment::create(['provider' => 'fake', 'amount' => 2000, 'currency' => 'USD']);

        $this->assertNull($one->provider_transaction_id);
        $this->assertNull($two->provider_transaction_id);
        $this->assertFalse($one->is($two));
    }

    public function test_database_unique_constraint_rejects_duplicate_provider_transaction_ids(): void
    {
        Payment::create([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'provider_transaction_id' => 'tx_1',
        ]);

        $this->expectException(QueryException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => 2000,
            'currency' => 'USD',
            'provider_transaction_id' => 'tx_1',
        ]);
    }

    public function test_create_idempotent_does_not_mask_a_provider_transaction_id_collision_as_success(): void
    {
        $service = app(PaymentService::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'provider_transaction_id' => 'tx_shared',
        ]);

        // A different idempotency_key, but a provider_transaction_id that
        // collides with the row above: the resulting QueryException must
        // propagate, not be swallowed as if this were a resent request
        // for "order-999" (no such row exists yet under that key).
        $this->expectException(QueryException::class);

        $service->createIdempotent([
            'provider' => 'fake',
            'amount' => 2000,
            'currency' => 'USD',
            'idempotency_key' => 'order-999',
            'provider_transaction_id' => 'tx_shared',
        ]);
    }
}
