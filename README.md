# Laravel Payment Reconciliation

[![tests](https://github.com/RaedElNABOULSI/laravel-payment-reconciliation/actions/workflows/tests.yml/badge.svg)](https://github.com/RaedElNABOULSI/laravel-payment-reconciliation/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/RaedElNABOULSI/laravel-payment-reconciliation/branch/main/graph/badge.svg)](https://codecov.io/gh/RaedElNABOULSI/laravel-payment-reconciliation)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Your payment integration already works. This package makes the payment lifecycle reliable.**

This is not a payment SDK and not a provider-specific integration. It doesn't talk to Stripe or any
gateway's charge API for you. What it does is own the part every payment integration eventually gets
wrong by hand: tracking a payment through an explicit state machine, making retries and webhooks
idempotent under concurrency, and detecting - safely, without guessing - when your local record and
the provider's record disagree.

## Table of contents

- [Why payment integrations become inconsistent](#why-payment-integrations-become-inconsistent)
- [Why not just cron + poll the provider yourself?](#why-not-just-cron--poll-the-provider-yourself)
- [What this package does NOT do](#what-this-package-does-not-do)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database setup](#database-setup)
- [Quickstart (zero config, no real provider needed)](#quickstart-zero-config-no-real-provider-needed)
- [Creating payments](#creating-payments)
- [State transitions](#state-transitions)
- [The UNKNOWN state](#the-unknown-state)
- [Webhook handling](#webhook-handling)
- [Observability - where to see results and errors](#observability---where-to-see-results-and-errors)
- [Idempotency](#idempotency)
- [Reconciliation](#reconciliation)
- [Provider adapter architecture](#provider-adapter-architecture)
- [Artisan commands](#artisan-commands)
- [Scheduler integration](#scheduler-integration)
- [Events](#events)
- [Testing](#testing)
- [Extension / custom providers](#extension--custom-providers)
- [Security considerations](#security-considerations)
- [Roadmap](#roadmap)

## Why payment integrations become inconsistent

A "simple" payment integration is a distributed system: your app, the provider's API, and the
provider's webhook delivery all have to agree on what happened, and any of them can fail
independently.

- A charge request times out - did it succeed on the provider's side or not? You can't tell from the
  timeout alone.
- A webhook is delivered twice (providers guarantee *at-least-once* delivery, not exactly-once).
- Two webhook deliveries for the same event arrive concurrently and both pass a naive
  `if ($alreadyProcessed) return;` check before either has written anything.
- Your local row says `paid`, a refund or dispute later makes the provider disagree, and nothing
  ever notices.

None of these are exotic edge cases - they are the normal operating conditions of any payment
integration that runs long enough. This package gives you the primitives to handle them correctly
once, instead of re-deriving them per project.

## Why not just cron + poll the provider yourself?

This is the honest alternative every evaluator should compare against - a five-line scheduled job
that polls the provider and updates a `status` column looks like it does the same thing. What it
usually doesn't handle:

- **Concurrency.** Two webhook deliveries (or a webhook and a poll) landing at the same time will
  both read `status = processing`, both decide to update it, and one silently overwrites the other's
  work. This package closes that gap with unique database constraints plus `lockForUpdate()`
  transactions - not a `SELECT` followed by an `UPDATE`.
- **Idempotency.** A retried webhook or a retried API call needs to be recognized as *the same
  operation*, not reprocessed. That requires unique constraints on the right columns from the start,
  not a `processed_ids` array you remember to check.
- **A real state machine.** "Just update the status" tends to accept any transition, including the
  ones that corrupt data - a `paid` payment reverting to `pending`, or a `cancelled` payment somehow
  becoming `paid`. This package makes invalid transitions raise, not "just happen."
- **The unknown state.** A naive poller usually treats "I don't know" the same as "it failed," which
  means a network blip can cost you a paid order. This package treats "unknown" as a first-class,
  distinct state that safely resolves later.

If your integration is genuinely simple and none of the above applies yet, you may not need this
package. It exists for the moment those things start to matter.

## What this package does NOT do

- It does **not** process payments, charge cards, or talk to a bank/acquirer.
- It does **not** hold, move, or custody funds.
- It does **not** replace PCI-DSS-relevant handling of card data - it never sees a card number and
  has no opinion on how you collect one.
- It does **not** ship a working Stripe/Tap/Areeba integration in this version (see
  [Roadmap](#roadmap)) - it ships the contract and a fake provider so you can build one, and the
  currently-implemented gateways are limited to what's listed there.
- It does **not** provide a dashboard/UI in this version.

## Installation

```bash
composer require vendor/laravel-payment-reconciliation
```

Laravel auto-discovers `PaymentReconciliationServiceProvider`. No manual registration needed.

## Configuration

```bash
php artisan vendor:publish --tag=payment-reconciliation-config
```

This publishes `config/payment-reconciliation.php`:

```php
return [
    // The provider used when your code doesn't specify one explicitly.
    'default_provider' => env('PAYMENT_RECONCILIATION_PROVIDER', 'fake'),

    // Maps a provider name (as stored on payments.provider) to its adapter class.
    'providers' => [
        'fake' => \VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider::class,
    ],

    // Override if these clash with existing tables in your app.
    'table_names' => [
        'payments' => 'payments',
        'webhook_events' => 'webhook_events',
    ],
];
```

## Database setup

Migrations are auto-loaded, so `php artisan migrate` works immediately with no publish step. If you
need to customize the schema (e.g. via a different table name from config, applied *before*
migrating), publish them first:

```bash
php artisan vendor:publish --tag=payment-reconciliation-migrations
php artisan migrate
```

This creates two tables:

- **`payments`** - the package-owned payment record (UUID primary key, `payable` polymorphic
  relation + a raw `payable_reference` fallback, provider identifiers, integer `amount`, `currency`,
  `status`, `provider_status`, `idempotency_key`, `metadata` JSON column, `last_reconciled_at`).
- **`webhook_events`** - one row per successfully-claimed webhook delivery, the backbone of webhook
  idempotency.

## Quickstart (zero config, no real provider needed)

The `fake` provider is bound by default, so you can exercise the entire lifecycle right after
`migrate` - no gateway account, no API keys:

```php
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider;
use VendorName\LaravelPaymentReconciliation\Reconciliation\ReconciliationService;
use VendorName\LaravelPaymentReconciliation\Services\PaymentService;

$paymentService = app(PaymentService::class);
$fakeProvider = app(FakePaymentProvider::class); // bound as a singleton

// 1. Create a payment (defaults to `pending`).
$payment = $paymentService->create([
    'provider' => 'fake',
    'amount' => 4200, // cents - never a float
    'currency' => 'USD',
]);

// 2. Move it into processing once you've handed off to the provider.
$payment = $paymentService->markProcessing($payment);
$payment->update(['provider_transaction_id' => 'tx_demo_123']);

// 3. The provider confirms the charge (e.g. a synchronous API response) -
//    amount/currency are checked before this is accepted.
$payment = $paymentService->markPaid($payment, actualAmount: 4200, actualCurrency: 'USD');
echo $payment->status->value; // "paid"

// 4. Some time later, a scheduled reconciliation run confirms both sides
//    still agree - this is the same call `payments:reconcile` makes.
$fakeProvider->setProviderStatus('tx_demo_123', PaymentStatus::Paid, 4200, 'USD');
$result = app(ReconciliationService::class)->reconcile($payment, $fakeProvider);

echo $result->type->value; // "matched"
```

Run it inside `php artisan tinker` right after installing to see the full
`pending -> processing -> paid -> reconciled` flow with nothing but this package and Laravel. See
[Reconciliation](#reconciliation) for what happens instead when the two sides *don't* agree.

## Creating payments

```php
$payment = Payment::create([
    'provider' => 'custom',
    'amount' => 10000, // integer minor units (cents) - floats are rejected
    'currency' => 'USD',
]);
```

Amounts are stored and validated as integers via a dedicated Eloquent cast. Passing a float
(`10000.50`) throws `InvalidAmountException` immediately - it is never silently truncated or rounded.

### Linking a payment to your own model

```php
// Eloquent model on your side (default mechanism):
$payment = Payment::create([
    'provider' => 'custom',
    'amount' => 10000,
    'currency' => 'USD',
    'payable_type' => Order::class,
    'payable_id' => $order->id,
]);
$payment->payable; // returns the Order

// No Eloquent model at all - use the raw reference fallback:
$payment = Payment::create([
    'provider' => 'custom',
    'amount' => 10000,
    'currency' => 'USD',
    'payable_reference' => 'external-order-999',
]);
```

## State transitions

```php
$payment->transitionTo(PaymentStatus::Processing);
```

or via the service (identical behavior - the model method is a thin, ergonomic wrapper around it):

```php
app(PaymentService::class)->markProcessing($payment);
```

Allowed transitions:

```
pending    -> processing, cancelled
processing -> paid, failed, unknown
unknown    -> paid, failed
paid       -> (terminal)
failed     -> (terminal)
cancelled  -> (terminal)
```

Anything else - `paid -> pending`, `paid -> failed`, `cancelled -> paid`, `pending -> paid`, ... -
throws `InvalidStateTransitionException` and changes nothing. Repeating a transition the payment is
already in (e.g. calling `markProcessing()` twice) is a safe no-op, not an error - this keeps
retried application calls and resent webhooks idempotent.

**`transitionTo()`/`Payment::transitionTo()` refuse `PaymentStatus::Paid`** and throw
`InvalidArgumentException` if you try - a bare status transition has no way to verify anything, and
`paid` is the one state where that matters. Use `markPaid()` (service or model) instead:

```php
$payment = $paymentService->markPaid($payment, actualAmount: 4200, actualCurrency: 'USD');
// or, on the model:
$payment = $payment->markPaid(actualAmount: 4200, actualCurrency: 'USD');
```

This is the only path that can reach `paid`, on the service, the model, webhook processing, and
automatic `unknown -> paid` reconciliation alike - there is no code path that mutates `status` to
`paid` without going through this check.

## The UNKNOWN state

If your app can't determine whether a payment succeeded (a timeout while calling the provider, a
dropped connection), mark it `unknown` instead of guessing:

```php
app(PaymentService::class)->markUnknown($payment);
```

An `unknown` payment is never auto-resolved to `failed` by this package. Reconciliation is the only
thing allowed to resolve it, and only in one direction based on the provider's own answer:

```
unknown -> paid    (provider confirms success)
unknown -> failed  (provider confirms failure)
unknown -> unknown (provider is still unsure, or unreachable - no change)
```

## Webhook handling

```php
use VendorName\LaravelPaymentReconciliation\Webhooks\WebhookProcessor;
use VendorName\LaravelPaymentReconciliation\Exceptions\WebhookVerificationException;
use VendorName\LaravelPaymentReconciliation\Exceptions\UnknownProviderTransactionException;

Route::post('/webhooks/{provider}', function (Request $request, string $provider) {
    $providerAdapter = app(ProviderRegistry::class)->resolve($provider);

    try {
        $result = app(WebhookProcessor::class)->process(
            $request->all(),
            $request->headers->all(),
            $providerAdapter,
            $request->getContent(), // raw body - required by providers that sign raw bytes (Stripe, etc.)
        );
    } catch (WebhookVerificationException $e) {
        return response()->json(['error' => 'invalid signature'], 400);
    } catch (UnknownProviderTransactionException $e) {
        return response()->json(['error' => 'unknown transaction'], 404);
    }

    return response()->json(['status' => $result->type->value]);
});
```

`WebhookProcessor::process()`:

1. Verifies the payload against the provider's own signature check
   (`PaymentProvider::verifyWebhook()`) - forged payloads never touch the database.
2. Normalizes the payload via `PaymentProvider::parseWebhookPayload()`.
3. Atomically claims `(provider, event_id)` - see [Idempotency](#idempotency).
4. Locks the matching payment row (`lockForUpdate()`), validates amount/currency before accepting
   `paid`, and applies the transition through the same state machine used everywhere else.

The result is one of:

- **`Processed`** - a new, authentic event that changed (or confirmed) state.
- **`Duplicate`** - the same `(provider, event_id)` was already processed; nothing happened.
- **`Rejected`** - a new, authentic event that described something unsafe (an amount/currency
  mismatch, or a transition the state machine disallows). Nothing was changed; a
  `PaymentMismatchDetected` event was fired so you can alert on it.

## Observability - where to see results and errors

This package has no dashboard and does not log anything to a file or service by default. On a
fresh install, the only place anything is visible is the database. Everything else is opt-in:

- **The `payments` table is the source of truth.** Query it directly, or run
  `php artisan payments:status <uuid>` for a one-payment snapshot in the terminal.
- **The `webhook_events` table** is an audit trail of every accepted webhook delivery, including its
  raw payload - useful for "did we actually receive this."
- **Events are fired but nothing listens to them until you do.** `PaymentMismatchDetected` and
  `PaymentBecameUnknown` are the two worth alerting on - register listeners for them (in your
  `EventServiceProvider`, or `Event::listen(...)`) if you want a log line, a Slack message, or a
  Sentry breadcrumb when they happen. See [Events](#events) for the full list.
- **`payments:reconcile`'s console output is not captured anywhere** when run via the scheduler,
  unless you add it yourself:
  ```php
  Schedule::command('payments:reconcile')
      ->hourly()
      ->appendOutputTo(storage_path('logs/payments-reconcile.log'));
  ```
- **The one thing logged automatically**: `ReconciliationService` calls `Log::warning()` when a
  provider is unreachable, which goes to your app's default log channel
  (`storage/logs/laravel.log` unless configured otherwise). Every other outcome - matched, mismatch,
  resolved - only reaches a log if your event listener puts it there.
- **Exceptions from webhook processing** (`WebhookVerificationException`,
  `UnknownProviderTransactionException`, `PaymentIntegrityException`,
  `InvalidStateTransitionException`) bubble up to wherever *you* called `WebhookProcessor::process()`
  - typically your controller, as shown above. The package doesn't log or swallow them for you.

## Idempotency

Two independent mechanisms, both backed by database constraints rather than application-level
checks:

- **Payment creation**: pass an `idempotency_key`, and `PaymentService::createIdempotent()` returns
  the existing payment on a retried request instead of creating a duplicate. The safety net is a
  unique constraint on `idempotency_key` - the code *attempts* the insert and catches the unique
  violation, it never does a check-then-insert.
- **Webhook delivery**: `webhook_events` has a unique constraint on `(provider, event_id)`. The
  insert is the concurrency check.

```php
$payment = app(PaymentService::class)->createIdempotent([
    'provider' => 'custom',
    'amount' => 10000,
    'currency' => 'USD',
    'idempotency_key' => (string) $request->header('Idempotency-Key'),
]);
```

Every state transition also runs inside `DB::transaction()` with `lockForUpdate()` on the payment
row, so two concurrent transition attempts serialize instead of racing.

## Reconciliation

```php
$result = app(ReconciliationService::class)->reconcile($payment, $providerAdapter);
```

Reconciliation never blindly copies the provider's state onto your local record. It produces a
`ReconciliationResult` describing what it found:

| Local status | Provider says | Result                                        |
|--------------|---------------|------------------------------------------------|
| `unknown`    | `paid`/`failed` | `Resolved` - the only case that auto-applies a transition |
| `unknown`    | still unclear | `StillUnknown` - no change                     |
| anything else | matches      | `Matched` - only `last_reconciled_at` updates  |
| anything else | disagrees (status, amount, or currency) | `Mismatch` - nothing changes, `PaymentMismatchDetected` fires |
| any          | provider unreachable | `ProviderUnavailable` - nothing changes, never treated as failure |

## Provider adapter architecture

```php
interface PaymentProvider
{
    public function getName(): string;
    public function getPaymentStatus(Payment $payment): ProviderPaymentStatus;
    public function verifyWebhook(array $payload, array $headers = [], ?string $rawBody = null): bool;
    public function parseWebhookPayload(array $payload): ProviderWebhookPayload;
}
```

```
PaymentProvider
    |
    +-- FakePaymentProvider   (ships with this package - tests + quickstart)
    +-- StripeProvider        (planned - see Roadmap)
    +-- YourCustomProvider
```

`getPaymentStatus()` must throw `ProviderUnavailableException` (not return a guess) when the
provider can't be reached - see [The UNKNOWN state](#the-unknown-state) for why that distinction
matters.

`verifyWebhook()`'s `$rawBody` parameter exists because several major providers (Stripe,
Checkout.com, Square, Worldpay, Razorpay, PayTabs, ...) compute their webhook signature over the
*exact raw bytes* of the request body, not over specific fields. Re-serializing `$payload` with
`json_encode()` is not guaranteed to reproduce those bytes (key order, whitespace, and number
formatting can all differ), so those adapters need the original string. Always pass it through from
your controller (`$request->getContent()` in Laravel) even though it's optional - providers that
sign specific parsed fields instead (Adyen, Tap, Braintree) simply ignore it.

## Artisan commands

```bash
php artisan payments:reconcile
php artisan payments:reconcile --provider=stripe
php artisan payments:reconcile --status=unknown
php artisan payments:reconcile --payment=<uuid>

php artisan payments:status <uuid>
```

With no filters, `payments:reconcile` only processes non-terminal payments (`pending`, `processing`,
`unknown`) - re-scanning every `paid` payment on every scheduled run would be an unbounded, pointless
table scan as your data grows. Pass `--status=paid` explicitly if you ever need to re-verify
terminal payments.

## Scheduler integration

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('payments:reconcile --status=unknown')->everyFifteenMinutes();
Schedule::command('payments:reconcile')->hourly();
```

The package only provides the command - how often it runs is entirely up to your application.

## Events

| Event | Fired when |
|---|---|
| `PaymentCreated` | A payment is created |
| `PaymentProcessing` | Transitioned to `processing` |
| `PaymentPaid` | Transitioned to `paid` |
| `PaymentFailed` | Transitioned to `failed` |
| `PaymentCancelled` | Transitioned to `cancelled` |
| `PaymentBecameUnknown` | Transitioned to `unknown` |
| `PaymentReconciled` | Any reconciliation run completes (check `$event->result->type`) |
| `PaymentMismatchDetected` | Local/provider state disagree, or a webhook described an unsafe change |
| `WebhookDuplicateDetected` | A `(provider, event_id)` was seen more than once |

## Testing

```bash
composer test      # PHPUnit
composer analyse   # PHPStan (Larastan) at level: max
```

`composer test` defaults to an in-memory SQLite database via Orchestra Testbench.
`FakePaymentProvider` is what the suite uses to simulate provider responses, timeouts, and signed
webhooks - see `tests/Feature/*Test.php` for the exact patterns (`setProviderStatus()`,
`simulateUnavailable()`, `sign()`).

SQLite has two real limitations for a payments package: `lockForUpdate()` is a no-op at the SQL
level there (Laravel's SQLite grammar emits no `FOR UPDATE` clause), and two connections to
`:memory:` are two unrelated empty databases, so genuine multi-connection lock contention can't be
exercised against it. Set `DB_CONNECTION=mysql` (plus `DB_HOST`/`DB_PORT`/`DB_DATABASE`/
`DB_USERNAME`/`DB_PASSWORD` as needed - see `tests/TestCase.php`) to run the same suite against a
real MySQL database instead; CI does this in a dedicated job on every push, and
`ConcurrencyIntegrationTest` (which proves a second connection is actually blocked by a held row
lock) only runs there.

## Extension / custom providers

Implement `PaymentProvider`, register it in config, and use it exactly like the built-in one:

```php
// config/payment-reconciliation.php
'providers' => [
    'fake' => \VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider::class,
    'my_gateway' => \App\Payments\MyGatewayProvider::class,
],
```

```php
$payment = $paymentService->create(['provider' => 'my_gateway', 'amount' => 10000, 'currency' => 'USD']);
```

`ProviderRegistry::resolve('my_gateway')` (used internally by `payments:reconcile` and available to
your own code) will now return your adapter.

## Security considerations

- Webhook payloads are only trusted after `PaymentProvider::verifyWebhook()` passes - implement this
  with your provider's actual signing scheme (HMAC, etc.), not a shared-secret string in the payload.
- A webhook reporting `paid` is never sufficient on its own: amount and currency are checked against
  the local record before any transition to `paid` is attempted.
- This package does not log full webhook payloads or payment metadata by default beyond what you
  explicitly pass into `metadata` - avoid putting card data or other PCI-scoped values there.
- Table and column names are configurable; if you rename `payments`/`webhook_events`, make sure any
  direct SQL or reporting elsewhere in your app is updated too.

## Roadmap

- **v0.2 (next, prioritized)**: a minimal, well-tested `StripeProvider` covering
  `getPaymentStatus()` and `verifyWebhook()` only - so a new user can go from `composer require` to
  a working real-provider integration in minutes, not by writing the adapter themselves first.
- Additional provider adapters (Tap, others) once the Stripe adapter's shape has proven itself.
- An optional dashboard for browsing payments and reconciliation history - not planned before the
  core (including at least one real provider) is stable.

No provider beyond `FakePaymentProvider` is implemented as of this version - don't configure
`stripe`/`tap`/etc. as a provider name until their adapters ship.
