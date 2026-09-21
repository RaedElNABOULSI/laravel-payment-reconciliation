<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Support\Facades\Event;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Events\PaymentBecameUnknown;
use VendorName\LaravelPaymentReconciliation\Events\PaymentCreated;
use VendorName\LaravelPaymentReconciliation\Events\PaymentPaid;
use VendorName\LaravelPaymentReconciliation\Events\PaymentProcessing;
use VendorName\LaravelPaymentReconciliation\Exceptions\InvalidStateTransitionException;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;
use VendorName\LaravelPaymentReconciliation\Tests\TestCase;

class PaymentTransitionTest extends TestCase
{
    public function test_creating_a_payment_defaults_to_pending_and_fires_event(): void
    {
        Event::fake();

        $payment = app(PaymentService::class)->create([
            'provider' => 'fake',
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        $this->assertTrue($payment->status === PaymentStatus::Pending);
        Event::assertDispatched(PaymentCreated::class);
    }

    public function test_model_transition_to_delegates_to_service_and_persists(): void
    {
        Event::fake();

        $payment = Payment::create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);

        $updated = $payment->transitionTo(PaymentStatus::Processing);

        $this->assertSame(PaymentStatus::Processing, $updated->status);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        Event::assertDispatched(PaymentProcessing::class);
    }

    public function test_full_happy_path_pending_processing_paid(): void
    {
        Event::fake();

        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markProcessing($payment);
        $payment = $service->markPaid($payment);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Event::assertDispatched(PaymentPaid::class);
    }

    public function test_processing_can_become_unknown_then_resolve_to_paid(): void
    {
        Event::fake();

        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markProcessing($payment);
        $payment = $service->markUnknown($payment);

        $this->assertSame(PaymentStatus::Unknown, $payment->status);
        Event::assertDispatched(PaymentBecameUnknown::class);

        $payment = $service->markPaid($payment);

        $this->assertSame(PaymentStatus::Paid, $payment->status);
    }

    public function test_unknown_can_resolve_to_failed(): void
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markProcessing($payment);
        $payment = $service->markUnknown($payment);
        $payment = $service->markFailed($payment);

        $this->assertSame(PaymentStatus::Failed, $payment->status);
    }

    public function test_paid_cannot_transition_to_pending(): void
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markProcessing($payment);
        $payment = $service->markPaid($payment);

        $this->expectException(InvalidStateTransitionException::class);

        $service->transitionTo($payment, PaymentStatus::Pending);
    }

    public function test_paid_cannot_transition_to_failed(): void
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markProcessing($payment);
        $payment = $service->markPaid($payment);

        $this->expectException(InvalidStateTransitionException::class);

        $service->markFailed($payment);
    }

    public function test_cancelled_cannot_transition_to_paid(): void
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markCancelled($payment);

        $this->expectException(InvalidStateTransitionException::class);

        $service->markPaid($payment);
    }

    public function test_status_does_not_change_on_disk_when_transition_is_invalid(): void
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => 5000, 'currency' => 'USD']);
        $payment = $service->markProcessing($payment);
        $payment = $service->markPaid($payment);

        try {
            $service->transitionTo($payment, PaymentStatus::Pending);
        } catch (InvalidStateTransitionException) {
            // expected
        }

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }
}
