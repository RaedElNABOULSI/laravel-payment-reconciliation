<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Raedev\LaravelPaymentReconciliation\Enums\PaymentStatus;
use Raedev\LaravelPaymentReconciliation\Events\PaymentMismatchDetected;
use Raedev\LaravelPaymentReconciliation\Events\PaymentReconciled;
use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Reconciliation\ReconciliationResultType;
use Raedev\LaravelPaymentReconciliation\Reconciliation\ReconciliationService;
use Raedev\LaravelPaymentReconciliation\Services\PaymentService;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

class ReconciliationTest extends TestCase
{
    private function makePayment(PaymentStatus $status, int $amount = 1000, string $currency = 'USD', string $txId = 'tx_1'): Payment
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => $amount, 'currency' => $currency]);
        $payment->update(['provider_transaction_id' => $txId]);
        $payment = $payment->fresh();

        return match ($status) {
            PaymentStatus::Pending => $payment,
            PaymentStatus::Processing => $service->markProcessing($payment),
            PaymentStatus::Unknown => $service->markUnknown($service->markProcessing($payment)),
            PaymentStatus::Paid => $service->markPaid($service->markProcessing($payment)),
            PaymentStatus::Failed => $service->markFailed($service->markProcessing($payment)),
            PaymentStatus::Cancelled => $service->markCancelled($payment),
        };
    }

    public function test_local_pending_provider_paid_is_reported_as_mismatch_not_applied(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Pending);
        $provider->setProviderStatus('tx_1', PaymentStatus::Paid, 1000, 'USD');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Mismatch, $result->type);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        Event::assertDispatched(PaymentMismatchDetected::class);
    }

    public function test_local_paid_provider_paid_matches(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Paid);
        $provider->setProviderStatus('tx_1', PaymentStatus::Paid, 1000, 'USD');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Matched, $result->type);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->last_reconciled_at);
        Event::assertDispatched(PaymentReconciled::class);
        Event::assertNotDispatched(PaymentMismatchDetected::class);
    }

    public function test_local_unknown_provider_paid_resolves_to_paid(): void
    {
        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Unknown);
        $provider->setProviderStatus('tx_1', PaymentStatus::Paid, 1000, 'USD');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Resolved, $result->type);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_local_unknown_provider_paid_with_amount_mismatch_is_reported_not_applied(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Unknown, amount: 1000);
        // Provider says "paid" but reports a different amount than expected -
        // auto-resolving unknown -> paid must not happen just because the
        // status field agrees.
        $provider->setProviderStatus('tx_1', PaymentStatus::Paid, 500, 'USD');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Mismatch, $result->type);
        $this->assertSame(PaymentStatus::Unknown, $payment->fresh()->status);
        Event::assertDispatched(PaymentMismatchDetected::class);
    }

    public function test_local_unknown_provider_failed_resolves_to_failed(): void
    {
        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Unknown);
        $provider->setProviderStatus('tx_1', PaymentStatus::Failed);

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Resolved, $result->type);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_local_unknown_provider_still_processing_stays_unknown(): void
    {
        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Unknown);
        $provider->setProviderStatus('tx_1', PaymentStatus::Processing);

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::StillUnknown, $result->type);
        $this->assertSame(PaymentStatus::Unknown, $payment->fresh()->status);
    }

    public function test_local_pending_provider_failed_is_reported_as_mismatch_not_applied(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Pending);
        $provider->setProviderStatus('tx_1', PaymentStatus::Failed);

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Mismatch, $result->type);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_amount_mismatch_is_detected_without_changing_state(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Paid, amount: 1000);
        $provider->setProviderStatus('tx_1', PaymentStatus::Paid, 500, 'USD');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Mismatch, $result->type);
        $this->assertStringContainsString('amount', $result->differences[0]);
        $this->assertSame(1000, $payment->fresh()->amount);
        Event::assertDispatched(PaymentMismatchDetected::class);
    }

    public function test_currency_mismatch_is_detected_without_changing_state(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Paid, currency: 'USD');
        $provider->setProviderStatus('tx_1', PaymentStatus::Paid, 1000, 'EUR');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::Mismatch, $result->type);
        $this->assertSame('USD', $payment->fresh()->currency);
        Event::assertDispatched(PaymentMismatchDetected::class);
    }

    public function test_provider_unavailable_does_not_mark_payment_failed(): void
    {
        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Processing);
        $provider->simulateUnavailable('tx_1');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::ProviderUnavailable, $result->type);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->last_reconciled_at);
    }

    public function test_provider_unavailable_while_unknown_does_not_mark_payment_failed(): void
    {
        $provider = $this->fakeProvider();
        $payment = $this->makePayment(PaymentStatus::Unknown);
        $provider->simulateUnavailable('tx_1');

        $result = app(ReconciliationService::class)->reconcile($payment, $provider);

        $this->assertSame(ReconciliationResultType::ProviderUnavailable, $result->type);
        $this->assertSame(PaymentStatus::Unknown, $payment->fresh()->status);
    }
}
