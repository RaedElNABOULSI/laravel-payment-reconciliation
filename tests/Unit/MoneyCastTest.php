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

    public function test_rejects_malformed_double_negative_amount_strings_instead_of_silently_zeroing_them(): void
    {
        // ltrim('--5', '-') === '5' passes a naive ctype_digit(ltrim(...))
        // check, but (int) '--5' is 0 in PHP - a malformed string must
        // not silently become a $0 payment.
        $this->expectException(InvalidAmountException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => '--5',
            'currency' => 'USD',
        ]);
    }

    public function test_rejects_triple_negative_amount_strings_too(): void
    {
        $this->expectException(InvalidAmountException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => '---42',
            'currency' => 'USD',
        ]);
    }

    public function test_rejects_negative_integer_amounts(): void
    {
        $this->expectException(InvalidAmountException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => -5,
            'currency' => 'USD',
        ]);
    }

    public function test_rejects_negative_string_amounts(): void
    {
        // The `amount` column is unsignedBigInteger - a well-formed
        // negative value must be rejected by the cast itself, not left
        // to fail (or worse, silently persist on SQLite) at the DB layer.
        $this->expectException(InvalidAmountException::class);

        Payment::create([
            'provider' => 'fake',
            'amount' => '-5',
            'currency' => 'USD',
        ]);
    }
}
