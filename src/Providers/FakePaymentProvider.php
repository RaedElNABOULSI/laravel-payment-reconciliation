<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Providers;

use Raedev\LaravelPaymentReconciliation\Contracts\PaymentProvider;
use Raedev\LaravelPaymentReconciliation\Contracts\ProviderPaymentStatus;
use Raedev\LaravelPaymentReconciliation\Contracts\ProviderWebhookPayload;
use Raedev\LaravelPaymentReconciliation\Enums\PaymentStatus;
use Raedev\LaravelPaymentReconciliation\Exceptions\ProviderUnavailableException;
use Raedev\LaravelPaymentReconciliation\Exceptions\WebhookVerificationException;
use Raedev\LaravelPaymentReconciliation\Models\Payment;

/**
 * An in-memory provider adapter with no external dependencies. It is
 * the provider used by the automated test suite and by the README
 * quickstart, so a developer can exercise the full payment lifecycle
 * (and simulate provider-side outcomes, timeouts, and webhooks) without
 * any real gateway credentials.
 *
 * State is held in memory for the lifetime of the process/request, so
 * this provider is bound as a singleton by the service provider.
 */
class FakePaymentProvider implements PaymentProvider
{
    /**
     * @var array<string, array{status: PaymentStatus, amount: ?int, currency: ?string}>
     */
    private array $providerState = [];

    /**
     * @var array<int, string> provider_transaction_ids that should raise ProviderUnavailableException
     */
    private array $unavailable = [];

    public function __construct(private readonly string $webhookSecret = 'fake-secret') {}

    public function getName(): string
    {
        return 'fake';
    }

    /**
     * Simulate the provider having reached a given state for a
     * transaction. Call this from tests or the quickstart script to
     * control what getPaymentStatus()/webhooks will report.
     */
    public function setProviderStatus(
        string $providerTransactionId,
        PaymentStatus $status,
        ?int $amount = null,
        ?string $currency = null,
    ): void {
        $this->providerState[$providerTransactionId] = [
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    /**
     * Simulate the provider being unreachable (timeout/outage) for a
     * transaction, so callers can verify they never treat "unknown" as
     * "failed".
     */
    public function simulateUnavailable(string $providerTransactionId): void
    {
        $this->unavailable[] = $providerTransactionId;
    }

    public function getPaymentStatus(Payment $payment): ProviderPaymentStatus
    {
        $providerTransactionId = $payment->provider_transaction_id;

        if ($providerTransactionId === null) {
            throw ProviderUnavailableException::make($this->getName());
        }

        if (in_array($providerTransactionId, $this->unavailable, true)) {
            throw ProviderUnavailableException::make($this->getName());
        }

        $state = $this->providerState[$providerTransactionId] ?? [
            'status' => PaymentStatus::Pending,
            'amount' => null,
            'currency' => null,
        ];

        return new ProviderPaymentStatus(
            status: $state['status'],
            providerTransactionId: $providerTransactionId,
            amount: $state['amount'],
            currency: $state['currency'],
        );
    }

    public function verifyWebhook(array $payload, array $headers = [], ?string $rawBody = null): bool
    {
        $signature = $headers['X-Fake-Signature'] ?? null;

        if (! is_string($signature)) {
            return false;
        }

        return hash_equals($this->sign($payload), $signature);
    }

    public function parseWebhookPayload(array $payload): ProviderWebhookPayload
    {
        $eventId = $payload['event_id'] ?? null;
        $providerTransactionId = $payload['provider_transaction_id'] ?? null;
        $statusValue = $payload['status'] ?? null;

        if (! is_string($eventId) && ! is_int($eventId)) {
            throw new WebhookVerificationException('Fake webhook payload is missing or has an invalid "event_id" field.');
        }

        if (! is_string($providerTransactionId) && ! is_int($providerTransactionId)) {
            throw new WebhookVerificationException('Fake webhook payload is missing or has an invalid "provider_transaction_id" field.');
        }

        $status = is_string($statusValue) ? PaymentStatus::tryFrom($statusValue) : null;

        if ($status === null) {
            throw new WebhookVerificationException('Fake webhook payload has an invalid "status" value.');
        }

        return new ProviderWebhookPayload(
            eventId: (string) $eventId,
            providerTransactionId: (string) $providerTransactionId,
            status: $status,
            amount: isset($payload['amount']) && is_numeric($payload['amount']) ? (int) $payload['amount'] : null,
            currency: isset($payload['currency']) && is_string($payload['currency']) ? $payload['currency'] : null,
            raw: $payload,
        );
    }

    /**
     * Compute the signature a genuine fake-provider webhook would carry,
     * so tests and the quickstart can construct valid requests.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $this->webhookSecret);
    }
}
