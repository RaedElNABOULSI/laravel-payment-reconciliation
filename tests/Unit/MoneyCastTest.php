<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Unit;

use Raedev\LaravelPaymentReconciliation\Exceptions\InvalidAmountException;
use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

class MoneyCastTest extends TestCase
{
    public function test_accepts_integer_amounts(): void
    {
        $payment = Payment::create([
            'provider' => 'fake',
            'amount' => 10000,
            'currency' => 'USD',
        ]);

        $this->assertSame(10000, $payment->amount);
    }

    public function test_accepts_numeric_string_amounts(): void
    {
        $payment = Payment::create([
            'provider' => 'fake',
            'amount' => '10000',
            'currency' => 'USD',
        ]);

        $this->assertSame(10000, $payment->amount);
    }

    public function test_rejects_float_amounts(): void
    {
        $this->expectException(InvalidAmountException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => 100.50,
            'currency' => 'USD',
        ]);
    }

    public function test_rejects_non_numeric_amounts(): void
    {
        $this->expectException(InvalidAmountException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => 'one hundred',
            'currency' => 'USD',
        ]);
    }
}
