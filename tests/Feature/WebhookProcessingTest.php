<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Support\Facades\Event;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Events\PaymentMismatchDetected;
use VendorName\LaravelPaymentReconciliation\Events\PaymentPaid;
use VendorName\LaravelPaymentReconciliation\Events\WebhookDuplicateDetected;
use VendorName\LaravelPaymentReconciliation\Exceptions\UnknownProviderTransactionException;
use VendorName\LaravelPaymentReconciliation\Exceptions\WebhookVerificationException;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Models\WebhookEvent;
use VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;
use VendorName\LaravelPaymentReconciliation\Tests\TestCase;
use VendorName\LaravelPaymentReconciliation\Webhooks\WebhookProcessor;
use VendorName\LaravelPaymentReconciliation\Webhooks\WebhookResultType;

class WebhookProcessingTest extends TestCase
{
    private function makeProcessingPayment(int $amount = 1000, string $currency = 'USD', string $txId = 'tx_1'): Payment
    {
        $service = app(PaymentService::class);

        $payment = $service->create(['provider' => 'fake', 'amount' => $amount, 'currency' => $currency]);
        $payment = $service->markProcessing($payment);
        $payment->update(['provider_transaction_id' => $txId]);

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function signedRequest(FakePaymentProvider $provider, array $payload): array
    {
        return [$payload, ['X-Fake-Signature' => $provider->sign($payload)]];
    }

    public function test_valid_webhook_marks_payment_paid(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makeProcessingPayment();

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_1',
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $result = app(WebhookProcessor::class)->process($payload, $headers, $provider);

        $this->assertSame(WebhookResultType::Processed, $result->type);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, WebhookEvent::query()->where('event_id', 'evt_1')->count());
        Event::assertDispatched(PaymentPaid::class);
    }

    public function test_duplicate_webhook_is_safely_ignored(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $this->makeProcessingPayment();

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_dup',
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $processor = app(WebhookProcessor::class);

        $first = $processor->process($payload, $headers, $provider);
        $second = $processor->process($payload, $headers, $provider);

        $this->assertSame(WebhookResultType::Processed, $first->type);
        $this->assertSame(WebhookResultType::Duplicate, $second->type);
        $this->assertSame(1, WebhookEvent::query()->where('event_id', 'evt_dup')->count());
        Event::assertDispatched(PaymentPaid::class, 1);
        Event::assertDispatched(WebhookDuplicateDetected::class);
    }

    public function test_three_identical_webhook_deliveries_only_transition_state_once(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makeProcessingPayment();
        $processor = app(WebhookProcessor::class);

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_tripled',
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $results = [
            $processor->process($payload, $headers, $provider)->type,
            $processor->process($payload, $headers, $provider)->type,
            $processor->process($payload, $headers, $provider)->type,
        ];

        $this->assertSame(
            [WebhookResultType::Processed, WebhookResultType::Duplicate, WebhookResultType::Duplicate],
            $results
        );
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Event::assertDispatched(PaymentPaid::class, 1);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $provider = $this->fakeProvider();
        $this->makeProcessingPayment();

        $this->expectException(WebhookVerificationException::class);

        app(WebhookProcessor::class)->process(
            ['event_id' => 'evt_bad', 'provider_transaction_id' => 'tx_1', 'status' => 'paid'],
            ['X-Fake-Signature' => 'not-the-right-signature'],
            $provider
        );
    }

    public function test_webhook_for_unknown_transaction_is_rejected(): void
    {
        $provider = $this->fakeProvider();

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_unknown',
            'provider_transaction_id' => 'tx_does_not_exist',
            'status' => 'paid',
        ]);

        $this->expectException(UnknownProviderTransactionException::class);

        app(WebhookProcessor::class)->process($payload, $headers, $provider);
    }

    public function test_amount_mismatch_does_not_mark_payment_paid(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makeProcessingPayment(amount: 1000);

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_amount_mismatch',
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
            'amount' => 500,
            'currency' => 'USD',
        ]);

        $result = app(WebhookProcessor::class)->process($payload, $headers, $provider);

        $this->assertSame(WebhookResultType::Rejected, $result->type);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        Event::assertDispatched(PaymentMismatchDetected::class);
        Event::assertNotDispatched(PaymentPaid::class);
    }

    public function test_currency_mismatch_does_not_mark_payment_paid(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makeProcessingPayment(currency: 'USD');

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_currency_mismatch',
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
            'amount' => 1000,
            'currency' => 'EUR',
        ]);

        $result = app(WebhookProcessor::class)->process($payload, $headers, $provider);

        $this->assertSame(WebhookResultType::Rejected, $result->type);
        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        Event::assertDispatched(PaymentMismatchDetected::class);
        Event::assertNotDispatched(PaymentPaid::class);
    }

    public function test_webhook_describing_an_invalid_transition_is_rejected_without_crashing(): void
    {
        Event::fake();

        $provider = $this->fakeProvider();
        $payment = $this->makeProcessingPayment();
        $payment = app(PaymentService::class)->markPaid($payment);

        // Provider resends an event claiming the (already paid) payment is
        // now "failed" - must never move a paid payment backwards.
        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_invalid_transition',
            'provider_transaction_id' => 'tx_1',
            'status' => 'failed',
        ]);

        $result = app(WebhookProcessor::class)->process($payload, $headers, $provider);

        $this->assertSame(WebhookResultType::Rejected, $result->type);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Event::assertDispatched(PaymentMismatchDetected::class);
    }

    public function test_webhook_confirming_already_paid_state_is_processed_as_a_no_op(): void
    {
        $provider = $this->fakeProvider();
        $payment = $this->makeProcessingPayment();
        $payment = app(PaymentService::class)->markPaid($payment);

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_resend_paid',
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $result = app(WebhookProcessor::class)->process($payload, $headers, $provider);

        $this->assertSame(WebhookResultType::Processed, $result->type);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_webhook_with_invalid_status_value_is_rejected_safely(): void
    {
        $provider = $this->fakeProvider();
        $this->makeProcessingPayment();

        [$payload, $headers] = $this->signedRequest($provider, [
            'event_id' => 'evt_bad_status',
            'provider_transaction_id' => 'tx_1',
            'status' => 'not_a_real_status',
        ]);

        $this->expectException(WebhookVerificationException::class);

        app(WebhookProcessor::class)->process($payload, $headers, $provider);
    }

    public function test_webhook_with_array_valued_field_is_rejected_safely(): void
    {
        $provider = $this->fakeProvider();
        $this->makeProcessingPayment();

        $payload = [
            'event_id' => ['nested' => 'array'],
            'provider_transaction_id' => 'tx_1',
            'status' => 'paid',
        ];
        $headers = ['X-Fake-Signature' => $provider->sign($payload)];

        $this->expectException(WebhookVerificationException::class);

        app(WebhookProcessor::class)->process($payload, $headers, $provider);
    }

    public function test_webhook_missing_status_entirely_is_rejected_safely(): void
    {
        $provider = $this->fakeProvider();
        $this->makeProcessingPayment();

        $payload = [
            'event_id' => 'evt_no_status',
            'provider_transaction_id' => 'tx_1',
        ];
        $headers = ['X-Fake-Signature' => $provider->sign($payload)];

        $this->expectException(WebhookVerificationException::class);

        app(WebhookProcessor::class)->process($payload, $headers, $provider);
    }

    public function test_raw_body_is_forwarded_to_the_provider_for_signature_verification(): void
    {
        $this->makeProcessingPayment();

        $provider = new class extends FakePaymentProvider
        {
            public ?string $capturedRawBody = 'not-called';

            public function verifyWebhook(array $payload, array $headers = [], ?string $rawBody = null): bool
            {
                $this->capturedRawBody = $rawBody;

                return true;
            }
        };

        $rawBody = '{"event_id":"evt_raw_body","provider_transaction_id":"tx_1","status":"paid","amount":1000,"currency":"USD"}';

        $result = app(WebhookProcessor::class)->process(
            [
                'event_id' => 'evt_raw_body',
                'provider_transaction_id' => 'tx_1',
                'status' => 'paid',
                'amount' => 1000,
                'currency' => 'USD',
            ],
            [],
            $provider,
            $rawBody,
        );

        $this->assertSame($rawBody, $provider->capturedRawBody);
        $this->assertSame(WebhookResultType::Processed, $result->type);
    }
}
