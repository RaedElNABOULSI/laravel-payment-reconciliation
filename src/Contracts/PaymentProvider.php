<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Contracts;

use Raedev\LaravelPaymentReconciliation\Exceptions\WebhookVerificationException;
use Raedev\LaravelPaymentReconciliation\Models\Payment;

/**
 * Contract that every payment provider adapter (Stripe, Tap, a custom
 * gateway, ...) must implement so the package can query and reconcile
 * payment state without knowing provider-specific details.
 */
interface PaymentProvider
{
    /**
     * The provider's own identifier, e.g. "stripe", "tap", "fake".
     * Must match the key used in the `providers` config map.
     */
    public function getName(): string;

    /**
     * Ask the provider for the current status of a payment.
     *
     * Implementations must throw a domain exception (not return a guess)
     * when the provider cannot be reached or the transaction id is
     * unknown to it, so callers can distinguish "confirmed state" from
     * "unable to determine state".
     */
    public function getPaymentStatus(Payment $payment): ProviderPaymentStatus;

    /**
     * Verify that an incoming webhook payload genuinely originated from
     * this provider (e.g. signature/HMAC verification against headers).
     *
     * $rawBody is the exact, unparsed request body bytes, when the
     * caller has them available. Pass it through whenever you have it:
     * several providers (Stripe, Checkout.com, Square, ...) compute
     * their signature over the raw byte string, and re-serializing
     * $payload is not guaranteed to reproduce those bytes (key order,
     * whitespace, and number formatting can all differ). Providers that
     * sign specific parsed fields instead (Adyen, Tap, Braintree, ...)
     * can safely ignore it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhook(array $payload, array $headers = [], ?string $rawBody = null): bool;

    /**
     * Normalise a verified webhook payload into the identifiers and
     * values the package needs to process it safely.
     *
     * Implementations must validate, not just cast: a missing, wrong-type,
     * or unrecognised value in any required field must throw rather than
     * silently coerce (e.g. an invalid `status` string must not reach
     * PaymentStatus::from() uncaught - use tryFrom() and throw on null).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookVerificationException if a required field is missing, the wrong type, or an unrecognised value.
     */
    public function parseWebhookPayload(array $payload): ProviderWebhookPayload;
}
