<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Generic payable relation: an Eloquent model via morphTo, or a
            // plain string/UUID reference when the host app has no model.
            $table->nullableMorphs('payable');
            $table->string('payable_reference')->nullable();

            $table->string('provider')->index();
            $table->string('provider_transaction_id')->nullable();

            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);

            $table->string('status')->default('pending')->index();
            $table->string('provider_status')->nullable();

            $table->string('idempotency_key')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();

            $table->timestamps();

            // NULLs are treated as distinct by MySQL/Postgres/SQLite unique
            // indexes, so multiple payments without a provider transaction
            // id yet remain valid under this constraint.
            $table->unique(['provider', 'provider_transaction_id']);
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }

    private function tableName(): string
    {
        return config('payment-reconciliation.table_names.payments', 'payments');
    }
};
