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
            $table->id();

            $table->string('provider')->index();
            $table->string('event_id');
            $table->foreignUuid('payment_id')
                ->nullable()
                ->constrained($this->paymentsTableName())
                ->nullOnDelete();

            $table->json('payload');

            $table->timestamps();

            // The single source of truth for webhook idempotency: a
            // concurrent duplicate delivery fails this constraint at
            // insert time, it is never merely detected by a prior read.
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }

    private function tableName(): string
    {
        return config('payment-reconciliation.table_names.webhook_events', 'webhook_events');
    }

    private function paymentsTableName(): string
    {
        return config('payment-reconciliation.table_names.payments', 'payments');
    }
};
