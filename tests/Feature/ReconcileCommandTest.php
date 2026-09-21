<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests\Feature;

use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;
use VendorName\LaravelPaymentReconciliation\Tests\TestCase;

class ReconcileCommandTest extends TestCase
{
    public function test_command_reconciles_only_non_terminal_payments_by_default(): void
    {
        $service = app(PaymentService::class);
        $provider = $this->fakeProvider();

        $unknown = $service->create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $unknown->update(['provider_transaction_id' => 'tx_unknown']);
        $unknown = $service->markUnknown($service->markProcessing($unknown->fresh()));
        $provider->setProviderStatus('tx_unknown', PaymentStatus::Paid, 1000, 'USD');

        $paid = $service->create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $paid->update(['provider_transaction_id' => 'tx_paid']);
        $paid = $service->markPaid($service->markProcessing($paid->fresh()));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $unknown->fresh()->status);
        $this->assertNotNull($unknown->fresh()->last_reconciled_at);
        // The already-terminal "paid" payment was skipped by the default filter.
        $this->assertNull($paid->fresh()->last_reconciled_at);
    }

    public function test_command_filters_by_status(): void
    {
        $service = app(PaymentService::class);
        $provider = $this->fakeProvider();

        $pending = $service->create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);

        $processing = $service->create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $processing->update(['provider_transaction_id' => 'tx_processing']);
        $processing = $service->markProcessing($processing->fresh());
        $provider->setProviderStatus('tx_processing', PaymentStatus::Paid, 1000, 'USD');

        $this->artisan('payments:reconcile', ['--status' => 'processing'])->assertExitCode(0);

        $this->assertNull($pending->fresh()->last_reconciled_at);
        $this->assertNotNull($processing->fresh()->last_reconciled_at);
    }

    public function test_command_filters_by_single_payment(): void
    {
        $service = app(PaymentService::class);
        $provider = $this->fakeProvider();

        $target = $service->create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $target->update(['provider_transaction_id' => 'tx_target']);
        $target = $service->markUnknown($service->markProcessing($target->fresh()));
        $provider->setProviderStatus('tx_target', PaymentStatus::Paid, 1000, 'USD');

        $other = $service->create(['provider' => 'fake', 'amount' => 1000, 'currency' => 'USD']);
        $other->update(['provider_transaction_id' => 'tx_other']);
        $other = $service->markUnknown($service->markProcessing($other->fresh()));
        $provider->setProviderStatus('tx_other', PaymentStatus::Paid, 1000, 'USD');

        $this->artisan('payments:reconcile', ['--payment' => $target->id])->assertExitCode(0);

        $this->assertSame(PaymentStatus::Paid, $target->fresh()->status);
        $this->assertSame(PaymentStatus::Unknown, $other->fresh()->status);
    }

    public function test_command_rejects_invalid_status_option(): void
    {
        $this->artisan('payments:reconcile', ['--status' => 'not-a-real-status'])
            ->assertExitCode(1);
    }
}
