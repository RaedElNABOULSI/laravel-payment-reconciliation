# Changelog

All notable changes to this package are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); this project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- **Breaking**: `PaymentService::transitionTo()` and `Payment::transitionTo()` now refuse
  `PaymentStatus::Paid` and throw `InvalidArgumentException`. Marking a payment paid must go
  through `PaymentService::markPaid()` (or the new `Payment::markPaid()` model method), which can
  verify amount/currency first. Previously, `transitionTo(Paid)` reached the `paid` state with no
  integrity check at all - the exact thing this package exists to prevent.
- `ReconciliationService`'s automatic `unknown -> paid` resolution now goes through `markPaid()`
  and verifies the provider's reported amount/currency before applying it; a mismatch now produces
  a `Mismatch` result instead of silently marking the payment paid.
- Repeating an already-applied transition (e.g. calling `markProcessing()` twice, or a webhook
  resending a status the payment is already in) is now a consistent, safe no-op everywhere,
  instead of the state machine rejecting it in some code paths and `WebhookProcessor` silently
  special-casing it in others.
- `payments:reconcile`'s default filter now queries non-terminal statuses with `whereIn` instead
  of excluding terminal ones with `whereNotIn`, so the `status` index can actually be used.

### Added

- `Payment::markPaid()` convenience method, mirroring `Payment::transitionTo()`.
- PHPStan (via Larastan) at `level: max`, wired into CI; `composer analyse`.
- A CI job running the full suite against a real MySQL database, in addition to SQLite, so the
  non-SQLite branches of `DetectsUniqueConstraintViolations` are actually exercised somewhere.
- A genuine multi-connection concurrency test (`ConcurrencyIntegrationTest`) proving row-level
  locking blocks a second connection - skipped under SQLite, where two connections to `:memory:`
  are unrelated empty databases and `lockForUpdate()` has no SQL-level effect.
- `verifyWebhook()` gained an optional `$rawBody` parameter so adapters for providers that sign
  the raw request body (Stripe, Checkout.com, Square, ...) can verify correctly.
- An "Observability" section in the README.

### Fixed

- `FakePaymentProvider::parseWebhookPayload()` (and, by extension, the pattern every real adapter
  should follow) now validates the `status`/`event_id`/`provider_transaction_id` fields instead of
  blindly casting them - a malformed or unrecognised `status` value previously caused an uncaught
  `ValueError` to escape `WebhookProcessor::process()`.
- `PaymentService::createIdempotent()` no longer masks a `(provider, provider_transaction_id)`
  unique-constraint collision as if it were an idempotency-key retry.
- `DetectsUniqueConstraintViolations` no longer risks misclassifying a NOT NULL or foreign-key
  violation as a duplicate on MySQL/SQLite (both report those under the same generic `23000` code
  `23505` doesn't).
- Removed a redundant standalone index on `payments.provider` (already covered by the composite
  unique index).
- `MoneyCast`'s own generic type annotation contradicted the runtime float-rejection check it
  implements, causing static analysis to flag that check as dead code.

## Notes

This package has not yet had a tagged release. Versions will be recorded here starting at the
first tag.
