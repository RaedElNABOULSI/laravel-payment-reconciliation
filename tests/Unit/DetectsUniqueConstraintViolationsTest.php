<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VendorName\LaravelPaymentReconciliation\Support\DetectsUniqueConstraintViolations;
use VendorName\LaravelPaymentReconciliation\Tests\TestCase;

class DetectsUniqueConstraintViolationsTest extends TestCase
{
    private function classifier(): object
    {
        return new class
        {
            use DetectsUniqueConstraintViolations;

            public function isViolation(QueryException $e): bool
            {
                return $this->isUniqueConstraintViolation($e);
            }
        };
    }

    public function test_detects_a_genuine_unique_constraint_violation(): void
    {
        DB::table('payments')->insert([
            'id' => (string) Str::uuid(),
            'provider' => 'fake',
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => 'dupe-key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(),
                'provider' => 'fake',
                'amount' => 2000,
                'currency' => 'USD',
                'status' => 'pending',
                'idempotency_key' => 'dupe-key',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('Expected a QueryException from the duplicate idempotency_key insert.');
        } catch (QueryException $e) {
            $this->assertTrue($this->classifier()->isViolation($e));
        }
    }

    public function test_does_not_misclassify_a_not_null_violation_as_a_duplicate(): void
    {
        try {
            // `currency` is NOT NULL - this must fail for a reason that has
            // nothing to do with uniqueness.
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(),
                'provider' => 'fake',
                'amount' => 1000,
                'currency' => null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('Expected a QueryException from the NOT NULL violation.');
        } catch (QueryException $e) {
            $this->assertFalse($this->classifier()->isViolation($e));
        }
    }
}
