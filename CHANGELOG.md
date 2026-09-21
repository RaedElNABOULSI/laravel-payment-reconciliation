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

- Support for Laravel 12 and 13 (`illuminate/support|database|console: ^12.0|^13.0`, `php: ^8.2`).
  Previously the package required `^10.0|^11.0`, which made it uninstallable on any Laravel
  project created after Laravel 11 - a fresh `laravel/laravel` install today pulls Laravel 12.
  Laravel 10 and 11 were briefly added as well, then **removed again**: both are past their
  official security-fix window as of the date this change was made (Laravel 10's security support
  ended February 2025, Laravel 11's ended March 2026 - see
  https://laravel.com/docs/releases#support-policy). Composer's advisory-blocking correctly
  refuses to install *any* release of either major as a result - this isn't a bug in this
  package's constraints, it's Composer declining to install known-vulnerable, unpatched
  dependencies. Continuing to test against and advertise support for EOL Laravel versions isn't
  appropriate for a package that manages payment state. Verified by actually installing into a
  real, freshly-created Laravel application, and by running the full suite against real Laravel 12
  (SQLite and MySQL); Laravel 13 requires PHP ^8.3, which wasn't available with a working DB
  extension in the environment this change was made in, so that combination is verified by clean
  dependency resolution (no advisories, no conflicts) and against the official Laravel 12->13
  upgrade guide (no listed breaking change touches any API this package uses) rather than by
  running the suite directly - CI's php-8.3/Laravel-13 job covers that gap going forward.
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
- Renamed placeholder `VendorName`/`vendor/laravel-payment-reconciliation` to the real package
  name and namespace throughout.
- `PaymentStateMachineTest`'s data providers used doc-comment `@dataProvider` annotations, which
  PHPUnit 12 (needed for the Laravel 13 combination above) removes support for entirely, not just
  deprecates - switched to the `#[DataProvider]` attribute, which works across PHPUnit 10-12.
- `PayableRelationTest` created an ad-hoc `orders` table in `setUp()` with no cleanup; harmless
  under SQLite's per-connection `:memory:` reset, but broke the very first time the suite ran
  against a real, persistent MySQL database.

## Notes

This package has not yet had a tagged release. Versions will be recorded here starting at the
first tag.
