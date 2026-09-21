<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests\Feature;

use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider;
use VendorName\LaravelPaymentReconciliation\Reconciliation\ReconciliationResultType;
use VendorName\LaravelPaymentReconciliation\Reconciliation\ReconciliationService;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;
use VendorName\LaravelPaymentReconciliation\Tests\TestCase;

/**
 * Mirrors the README "Quickstart" section verbatim so the documented
 * flow never silently drifts from what the code actually does.
 */
class ReadmeQuickstartTest extends TestCase
{
    public function test_readme_quickstart_flow(): void
    {
        $paymentService = app(PaymentService::class);
        $fakeProvider = app(FakePaymentProvider::class);

        $payment = $paymentService->create([
            'provider' => 'fake',
            'amount' => 4200,
            'currency' => 'USD',
        ]);
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        $payment = $paymentService->markProcessing($payment);
        $payment->update(['provider_transaction_id' => 'tx_demo_123']);

        // The provider confirmed the charge (e.g. a synchronous API response).
        $payment = $paymentService->markPaid($payment, actualAmount: 4200, actualCurrency: 'USD');
        $this->assertSame(PaymentStatus::Paid, $payment->status);

        // Later, a scheduled reconciliation run confirms the two sides still agree.
        $fakeProvider->setProviderStatus('tx_demo_123', PaymentStatus::Paid, 4200, 'USD');
        $result = app(ReconciliationService::class)->reconcile($payment, $fakeProvider);

        $this->assertSame(ReconciliationResultType::Matched, $result->type);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->last_reconciled_at);
    }
}
