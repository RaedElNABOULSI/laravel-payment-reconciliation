<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Console\Commands;

use Illuminate\Console\Command;
use VendorName\LaravelPaymentReconciliation\Models\Payment;

class PaymentStatusCommand extends Command
{
    protected $signature = 'payments:status {payment : The payment UUID}';

    protected $description = 'Show the local and last-known provider status for a single payment.';

    public function handle(): int
    {
        $payment = Payment::find($this->argument('payment'));

        if ($payment === null) {
            $this->error('Payment not found.');

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['ID', $payment->id],
            ['Provider', $payment->provider],
            ['Provider Transaction ID', $payment->provider_transaction_id ?? '-'],
            ['Amount (minor units)', $payment->amount],
            ['Currency', $payment->currency],
            ['Local Status', $payment->status->value],
            ['Provider Status (last known)', $payment->provider_status ?? '-'],
            ['Idempotency Key', $payment->idempotency_key ?? '-'],
            ['Last Reconciled At', $payment->last_reconciled_at?->toDateTimeString() ?? 'never'],
        ]);

        return self::SUCCESS;
    }
}
