<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Tests\TestCase;

class PayableRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
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
