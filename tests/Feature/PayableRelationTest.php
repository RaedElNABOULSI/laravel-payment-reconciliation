<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

class PayableRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // dropIfExists first: on drivers with a persistent test database
        // (e.g. MySQL in CI - see tests/TestCase.php), a plain create()
        // here would collide with the table left behind by a previous
        // test. This only ever worked by accident under SQLite's
        // per-connection ":memory:" database.
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');

        parent::tearDown();
    }

    public function test_payment_links_to_an_eloquent_model_via_morph_relation(): void
    {
        $order = TestOrder::create();

        $payment = Payment::create([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'payable_type' => TestOrder::class,
            'payable_id' => $order->id,
        ]);

        $this->assertTrue($payment->payable->is($order));
    }

    public function test_payment_falls_back_to_a_plain_reference_with_no_eloquent_model(): void
    {
        $payment = Payment::create([
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'payable_reference' => 'external-order-999',
        ]);

        $this->assertNull($payment->payable);
        $this->assertSame('external-order-999', $payment->payable_reference);
    }
}

class TestOrder extends Model
{
    protected $table = 'orders';

    protected $guarded = [];
}
