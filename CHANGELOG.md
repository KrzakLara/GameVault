# Changelog

All notable changes to GameVault. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

Prepared as **1.0.0**, the first stable portfolio release. Not tagged yet.

### Product

- Storefront: Discover page with a spotlight title, featured games, a "how it works" strip, shop
  by platform, and a Trending list whose ranking rule is stated on the page.
- Catalogue with server-side search, platform and genre filters, sorting, pagination and
  shareable URLs.
- Game detail with platform, genre, live stock, related titles and a market reference from
  CheapShark (PC titles only, with source, currency and fetch time), loaded as a deferred prop.
- Original cover art for every fictional title: an illustrated scene per genre, generated from the
  title's genre and slug as inline SVG, in portrait and landscape formats.
- Simulated, idempotent checkout with every outcome handled in the UI (created, replayed, sold out,
  validation, rate limited, busy, session expiry).
- Orders with live fulfillment progress (bounded, visibility-aware polling) and a key reveal on
  explicit request that is never stored in the browser.
- Admin console: live overview aggregates with a stock composition bar, order search and filters
  with a detail drawer and audited retry and cancel, inventory with synthetic key import, and the
  notification outbox with audited requeue.

### Architecture

- Laravel 12 on PHP 8.4, Vue 3 with strict TypeScript and Inertia v2, MySQL 8.4, Redis and Horizon.
- Key reservation with `FOR UPDATE SKIP LOCKED` and a verified-stockout fallback; READ COMMITTED
  with whole-transaction retries; checkout idempotency arbitrated by a unique index.
- Fulfillment with leases and fencing tokens; bounded retries; recovery of lost work.
- Transactional outbox for delivery e-mails (at-least-once, no key material).
- 17 CHECK constraints and composite foreign keys enforcing domain invariants in MySQL.
- Architecture decision records in `docs/adr`.

### Security

- Owner-only order access (404 for others), admin gate on every admin route, Horizon restricted
  to administrators in every environment.
- Keys encrypted at rest and revealed only by the owner's explicit, rate-limited, audited request.
- CSRF protection, login and checkout rate limits, request ids, generic error pages with debug off.

### Testing

- Pest suite on MySQL 8.4, including multi-process concurrency tests with independent PHP processes,
  SIGKILLed workers and a genuine InnoDB deadlock.
- Twenty Playwright journeys against the real stack in an isolated E2E environment, live
  Redis/Horizon checks, a plaintext key-leak scan and k6 load-test scenarios.
- GitHub Actions: backend, frontend and E2E jobs on every push.

### License

- MIT License (`LICENSE`); the Composer package is `larakrzak/gamevault`.

### Fixed

- Stuck-order recovery no longer starts a second retry chain while a fulfillment job waits out its
  backoff.
- Inertia page assertions resolve page files on case-sensitive filesystems (Linux CI).
- Game card badges no longer overlap on narrow cards.
- Admin search fields no longer collapse on small screens; admin tables no longer widen the page on
  mobile; sign-in and registration pages have a main landmark.

### Known limitations

- Payment and the key supplier are simulated.
- Delivery e-mails are at-least-once: a duplicate is possible after a publisher crash.
- `APP_KEY` rotation requires re-encrypting keys and recomputing fingerprints.
- No load-test figures are published yet, and there is no public deployment.
