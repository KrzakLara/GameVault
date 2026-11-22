<div align="center">

<img src="public/favicon.svg" width="64" alt="" />

# GameVault

**A marketplace for digital game keys, built with Laravel, Vue 3 and MySQL, and engineered so
that the last key is sold exactly once.**

Concurrency-safe checkout · idempotent orders · lease-based fulfillment · transactional outbox ·
verified with real MySQL processes, Playwright journeys and live Horizon checks

[![Quality Gate](https://github.com/KrzakLara/GameVault/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/KrzakLara/GameVault/actions/workflows/ci.yml?query=branch%3Amain)
![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel 12](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue-3.5-4FC08D?logo=vuedotjs&logoColor=white)
![TypeScript](https://img.shields.io/badge/TypeScript-strict-3178C6?logo=typescript&logoColor=white)
![Inertia v2](https://img.shields.io/badge/Inertia-v2-9553E9)
![MySQL 8.4](https://img.shields.io/badge/MySQL-8.4-4479A1?logo=mysql&logoColor=white)
![Redis 7.4](https://img.shields.io/badge/Redis-7.4-DC382D?logo=redis&logoColor=white)

</div>

![GameVault storefront: the Discover page](docs/screenshots/discover.jpg)

> [!NOTE]
> A portfolio project. All games are fictional, payment is **simulated**, keys are **synthetic**,
> and nothing is affiliated with a real store. The engineering is real: every claim below is backed
> by a check that was executed, and the results section says exactly which and when.

[Highlights](#engineering-highlights) · [Screenshots](#screenshots) · [Stack](#technology-stack) ·
[Features](#features) · [Quick start](#quick-start) · [Architecture](#architecture-overview) ·
[Testing](#testing) · [Results](#verified-results) · [CI](#ci-status) · [Public demo](#public-demo) ·
[AI](#how-i-used-ai) · [Docs](#documentation)

## Engineering highlights

- **Concurrency-safe checkout.** The key is reserved inside the checkout transaction with
  `FOR UPDATE SKIP LOCKED`, so simultaneous buyers take different keys instead of queueing, and a
  verified-stockout fallback means nobody is told "sold out" while a key could still become theirs.
  ([ADR 0001](docs/adr/0001-reserve-keys-with-skip-locked-and-verified-stockout.md))
- **Request idempotency.** One idempotency key per purchase intent, arbitrated by
  `UNIQUE(user_id, idempotency_key)`: double clicks, retries, reloads and lost responses never
  create a second order. ([ADR 0003](docs/adr/0003-database-enforced-checkout-idempotency.md))
- **No double allocation, enforced by MySQL.** Row locks, `UNIQUE(game_keys.order_id)`, composite
  foreign keys and 17 CHECK constraints make a key sold twice, or attached to another game's order,
  impossible to store. ([ADR 0006](docs/adr/0006-domain-invariants-enforced-by-mysql.md))
- **Transactional locking that survives contention.** READ COMMITTED isolation, deadlocks and
  lock-wait timeouts retried as whole transactions, and a safe, retryable 503 when contention
  outlasts the retries. ([ADR 0002](docs/adr/0002-read-committed-with-whole-transaction-retries.md))
- **Lease-based fulfillment with fencing tokens.** A worker claims an order with a lease and a fresh
  token; a crashed worker's lease is taken over, and a slow worker that lost its lease can no
  longer finalize. ([ADR 0004](docs/adr/0004-fulfillment-leases-and-fencing-tokens.md))
- **Transactional outbox.** The delivery e-mail is written in the same commit as the delivery, so
  it is never lost, never sent for a rolled-back delivery, and never contains the key.
  ([ADR 0005](docs/adr/0005-transactional-outbox-for-notifications.md))
- **Reliable retry and recovery.** Queue retries back off (5/15/45/135 s), attempts are capped so a
  failing order releases its key, and a recovery sweep picks up lost work without racing a
  scheduled retry.
- **Secure key reveal.** Keys are encrypted at rest and shown only on the owner's explicit,
  rate-limited and audited request; never in page props, listings, logs or e-mails.
- **Real concurrency tests.** Independent PHP processes on real MySQL are released through a barrier
  and the tests assert commit ordering, including SIGKILLed workers and a genuine InnoDB deadlock.
- **End-to-end and live checks.** Twenty Playwright journeys run against the real stack, followed by
  live Redis/Horizon checks, all in an isolated E2E environment that cannot touch development data.
- **CI on every push.** Backend (Pint, Larastan level 8, Pest on MySQL 8.4), frontend (vue-tsc,
  ESLint, Prettier, production build) and E2E jobs.
- **Original artwork.** Every cover is generated from the title's genre and slug as inline SVG:
  no image files, no copied art, the same cover on every page.

## Screenshots

Captured from the running application by `scripts/screenshots.sh` (Playwright against the seeded
E2E stack). Data is the deterministic E2E fixture set.

| Catalogue with server-side filters | Game detail |
| --- | --- |
| ![Catalogue](docs/screenshots/catalogue.jpg) | ![Game detail](docs/screenshots/game-detail.jpg) |

| Checkout (simulated) | Delivered order, key revealed on request |
| --- | --- |
| ![Checkout](docs/screenshots/checkout.jpg) | ![Order delivered](docs/screenshots/order-delivered.jpg) |

| Admin overview (live aggregates) | Failed order in the admin drawer |
| --- | --- |
| ![Admin overview](docs/screenshots/admin-dashboard.jpg) | ![Admin orders](docs/screenshots/admin-orders.jpg) |

| Admin inventory | Admin outbox |
| --- | --- |
| ![Admin inventory](docs/screenshots/admin-inventory.jpg) | ![Admin outbox](docs/screenshots/admin-outbox.jpg) |

| My orders | Mobile storefront |
| --- | --- |
| ![My orders](docs/screenshots/my-orders.jpg) | <img src="docs/screenshots/mobile-discover.jpg" width="300" alt="Mobile storefront" /> |

The game detail shows a console title: CheapShark only covers PC storefronts, so the market card
says so instead of showing a number. (The E2E stack replaces CheapShark with a local stub, so no
screenshot here shows third-party prices.)

## Technology stack

| Layer | Technology (exact versions from the lock files) |
| --- | --- |
| Backend | PHP 8.4.27, Laravel 12.73, inertia-laravel 2.0.28, Horizon 5.52, Ziggy 2.7 |
| Data | MySQL 8.4.13 (InnoDB, READ COMMITTED), Redis 7.4 (queues, cache, Horizon) |
| Frontend | Vue 3.5 (`<script setup>`, strict TypeScript 5.7), @inertiajs/vue3 2.0 (deferred props, partial reloads), Vite 6.1, Tailwind CSS 3.4, radix-vue / shadcn-vue primitives, lucide icons |
| Quality | Pest 3.8, Larastan 3.15 (PHPStan 2.3, level 8), Pint 1.32, vue-tsc 2.2, ESLint 9.21, Prettier 3.5 |
| Testing | multi-process MySQL concurrency harness, Playwright 1.64.1 (E2E, Chromium), k6 2.3.0 (load) |
| Runtime | Docker Compose: app, MySQL, Redis, Horizon, scheduler; isolated E2E profile |

## Product overview

Customers browse a catalogue of original titles for Steam, PlayStation and Xbox, buy a key in a
simulated checkout and receive it seconds later through an asynchronous fulfillment pipeline. The
key is revealed only when the owner asks for it. Operators get an admin console for orders,
inventory, failed fulfillments and the notification outbox.

The difficult part is invisible on purpose: when many buyers want the last key at once, exactly
one gets it and nobody is told "sold out" while it could still be theirs; when a queue delivers a
job twice or a worker dies half-way, no key is delivered twice or lost; a notification is never
lost and never contains a secret.

## Features

**Storefront**
- Discover page with featured titles, a *Trending* section whose ranking rule is stated on the page (most purchases in the last 14 days; labelled fallback when there are none) and real stock counts
- Catalogue with server-side search, platform/genre filters, sorting, pagination and shareable URLs
- Game detail with stock state, related titles and a **market reference** from CheapShark, loaded as a deferred prop so an external API can never delay the page
- Honest market data: PC titles only, always with source, currency (USD), scope and fetch time; console titles say "not applicable" instead of showing a misleading number

**Checkout and orders**
- Simulated, idempotent checkout: one idempotency key per purchase intent, re-sent on double clicks, retries, lost responses and reloads; the client never sends a price
- Every outcome handled in the UI: created, replayed, sold out (409), validation (422), rate limited (429 with Retry-After), busy (503, retried with the same key), session expiry
- Order page with live fulfillment progress (bounded polling with backoff, paused in background tabs)
- Key reveal on explicit action only: kept in memory, auto-hidden after two minutes, cleared on navigation, never in web storage, copy button with fallback

**Admin console** (existing admin routes, server-side gate)
- Overview from live aggregates: orders, inventory, low stock, outbox health, recent failures
- Orders: search by order id or customer e-mail, status and failure filters, detail drawer, full timeline, inventory movements and audit trail; audited retry and cancel (with reason) behind confirmation dialogs
- Inventory: per-title available/reserved/sold counts, low-stock filter, synthetic key import with per-line validation feedback
- Outbox: status filters including "retrying", failure reasons, audited requeue of one or all dead letters; Horizon link

**Engine**
- Concurrency-safe inventory (`FOR UPDATE SKIP LOCKED` with a verified-stockout fallback), 17 CHECK constraints, composite foreign keys
- Fulfillment with leases and fencing tokens; bounded retries; recovery of stuck orders
- Transactional outbox with fenced claims, exponential backoff and dead letters
- Request IDs across HTTP, logs, jobs and audit entries; owner-only policies (404 for others)

## Quick start

Requirements: Docker with Compose v2. Everything (PHP, Composer, Node) runs in containers.

```bash
cp .env.example .env
docker compose up -d mysql redis
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan db:seed --class=GameVaultSeeder   # fictional catalogue + synthetic keys
docker compose run --rm app npm ci
docker compose run --rm app npm run build
docker compose up -d app                                 # http://localhost:8000
docker compose --profile workers up -d                   # Horizon + scheduler (fulfillment, outbox, recovery)
docker compose run --rm app php artisan gamevault:admin you@example.com   # admin rights (CLI only, audited)
```

### Demo workflow

1. Register an account and open **Discover**; note the stated trending rule.
2. Search or filter the **Catalogue**; the URL reproduces the result.
3. Open a game: console titles show the "not applicable" market state; the fictional Steam titles have no CheapShark listing and say so. Set a real CheapShark game id in a title's `cheapshark_id` to see a live PC reference with source, currency and fetch time.
4. **Buy now · simulated**. Double-click it: still one order.
5. Watch the order move from *Queued* to *Delivering* to *Delivered* (Horizon must be running), then **Reveal key** and **Copy**. Leave the page: the key is gone from the screen.
6. Grant yourself admin rights (command above) and open **Admin**: live figures, the order you just placed, its timeline and audit trail.
7. Simulate a supplier outage and watch retries and the failed job in Horizon, then retry the order from the admin console:
   ```bash
   docker compose run --rm app php artisan gamevault:supplier-outage on    # non-production only, expires on its own
   docker compose run --rm app php artisan gamevault:supplier-outage off
   ```

## Architecture overview

```mermaid
flowchart LR
    subgraph Browser
        UI["Vue 3 + Inertia v2<br/>storefront · orders · admin"]
    end

    subgraph Laravel["Laravel 12 (PHP 8.4)"]
        HTTP["HTTP layer<br/>policies · admin gate · rate limiters<br/>request IDs · Form Requests"]
        CO["PlaceOrder<br/>idempotent checkout"]
        INV["InventoryAllocator<br/>SKIP LOCKED + verified stockout"]
        FUL["FulfillOrder<br/>lease + fencing token"]
        OBX["Outbox<br/>fenced claims · backoff · dead letters"]
        MKT["MarketPriceService<br/>CheapShark, cached"]
    end

    subgraph Workers["Horizon (Redis queues)"]
        QF["fulfillment queue<br/>DeliverGameKey"]
        QN["notifications queue<br/>PublishOutboxMessages"]
        SCH["scheduler<br/>outbox:publish · recover-stuck"]
    end

    DB[("MySQL 8.4 InnoDB<br/>READ COMMITTED<br/>CHECK constraints · composite FKs")]
    R[("Redis 7.4<br/>queues · cache · Horizon")]
    CS["CheapShark API<br/>(PC prices, USD)"]
    MAIL["Mailer"]

    UI -- "Inertia / JSON (XSRF)" --> HTTP
    HTTP --> CO --> INV --> DB
    CO -- "dispatch after commit" --> QF
    QF --> FUL --> DB
    FUL -- "outbox row in the same commit" --> OBX
    FUL -- "dispatch after commit" --> QN
    QN --> OBX --> MAIL
    SCH --> OBX
    SCH --> QF
    HTTP -. "deferred prop" .-> MKT --> CS
    MKT --- R
    QF --- R
    QN --- R
```

Deeper write-up of every guarantee and how it is enforced: [docs/architecture.md](docs/architecture.md).
The key decisions are recorded as [architecture decision records](docs/adr/README.md).

The checkout sequence, the fulfillment lifecycle, the outbox flow, the security model and the
trade-offs are in the [engineering deep dive](docs/engineering.md).

## Testing

```bash
# Backend: Pest on MySQL 8.4 (Unit, Feature, Integration incl. multi-process concurrency)
docker compose run --rm app php artisan test
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
docker compose run --rm --no-deps app composer validate --strict

# Frontend
docker compose run --rm --no-deps app npm run typecheck      # vue-tsc (strict) + Playwright sources
docker compose run --rm --no-deps app npm run lint:check
docker compose run --rm --no-deps app npm run format:check
docker compose run --rm --no-deps app npm run build

# Real stack (isolated E2E profile: gamevault_e2e database, Redis DBs 2/3, own logs)
scripts/e2e.sh                 # 20 Playwright journeys, see e2e/README.md
E2E_KEEP_RUNNING=1 scripts/e2e.sh && scripts/verify-horizon.sh   # live Redis/Horizon checks
scripts/loadtest.sh            # k6 scenarios + database correctness checks
scripts/screenshots.sh         # regenerates docs/screenshots
```

## Verified results

Runs of 2026-11-22 on Docker Desktop (Windows 10 host, 8 CPUs and 31 GiB for Docker) with PHP
8.4.27, MySQL 8.4.13 and Redis 7.4:

| Check | Command | Result |
| --- | --- | --- |
| Composer | `composer validate --strict` | valid |
| Code style | `vendor/bin/pint --test` | passed, 172 files |
| Static analysis | `vendor/bin/phpstan analyse` (Larastan, level 8) | no errors |
| Backend tests | `php artisan test` on MySQL 8.4 | **332 passed**, 0 failed, 0 skipped (1,891 assertions, 283 s): Unit 13, Feature 260, Integration 59 |
| Concurrency (included above) | `tests/Integration/Concurrency` | 18 passed: checkout 8, fulfillment 6, outbox 4, each with independent PHP processes on real MySQL |
| Backend tests as in CI | same suite with Redis unreachable and the phpredis extension unloaded | 330 passed, 0 failed (run before the two recovery tests below were added) |
| Types | `npm run typecheck` (vue-tsc and the Playwright sources) | 0 errors |
| Lint and format | `npm run lint:check`, `npm run format:check` | clean |
| Production build | `npm run build` | built |
| E2E | `scripts/e2e.sh` (Playwright 1.64.1, Chromium) | 21 passed (20 journeys and the link check), 0 failed, 0 flaky, retries disabled |
| Live Redis/Horizon | `scripts/verify-horizon.sh` | 14 of 14 checks passed (the run before the fix below: 12 of 14) |
| Key leak scan | `gamevault:scan-key-leaks` after the E2E and Horizon runs | 124 keys checked in logs, failed jobs, Horizon payloads, audit entries, order events and outbox: none found |
| Schema | `information_schema` after a fresh migration | 17 CHECK constraints |

**What the live checks caught.** During a simulated supplier outage, the scheduled
`gamevault:recover-stuck` sweep re-dispatched an order whose queue retry was still waiting out its
backoff, so the order was attempted 7 times instead of 5 and its retries ran ahead of the backoff
schedule. Recovery now waits `stuck_after_minutes` after a lease expired before it takes over; a
regression test that fails without the fix is part of the backend results above. The E2E, live
Horizon and key-scan figures in the table come from runs on the fixed code: the job was attempted
exactly 5 times, with gaps of 8.3, 16.6, 46.3 and 136.5 s against the configured 5, 15, 45 and
135 s (each gap includes the 1.2 s simulated supplier call and the worker pickup), and two recovery
sweeps forced into the backoff window re-dispatched nothing. A first E2E run after the fix lost one
journey to a 90-second freeze of the local Docker VM; the rerun passed. CI runs the E2E suite and
the live checks on every push (see below).

### Re-verification after the visual update

Runs of 2026-11-22 on the same machine, after the artwork and interface changes:

| Check | Result |
| --- | --- |
| Types, lint, format, build | 0 type errors; ESLint and Prettier clean; production build OK |
| Code style, static analysis | Pint passed; Larastan level 8, no errors |
| Backend tests | Full suite: 329 passed, 3 failed. Two order queries selected the game's columns without the new `genre` field, and strict mode turned the missing attribute into an error. After selecting it, the 79 tests in the affected files passed; the full suite runs again in CI on the next push. |
| E2E | 20 of 20 journeys passed. The link check hit its 60 s limit while the development stack ran alongside the E2E stack (54 requests at about 1 s each); it now follows links in batches of six and passed in 29 s with the same coverage. |
| Accessibility and layout | A DOM sweep of 11 pages at 390, 768 and 1440 px found no page-level horizontal overflow, no unnamed links or buttons, no unlabelled form fields, no images or SVG graphics without a text alternative, and exactly one `h1` and one `main` per page, after fixing admin tables that widened the page on phones and adding a `main` landmark to the sign-in pages. An automated axe-core audit was not run. |
| Screenshots | All recaptured from the running app with `scripts/screenshots.sh` |

The live Redis/Horizon checks and the key scan were not repeated: the visual update does not touch
the queue, fulfillment or outbox code.

## CI status

`.github/workflows/ci.yml` defines three parallel jobs, all check-only (no fixer steps that
always pass): **backend** (Composer validate, Pint, Larastan, full Pest suite on a MySQL 8.4
service including the concurrency tests), **frontend** (vue-tsc, ESLint, Prettier, production
build) and **e2e** (the Docker E2E stack, the 20 Playwright journeys, then the live Horizon checks;
reports and logs uploaded as artifacts).

[![Quality Gate](https://github.com/KrzakLara/GameVault/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/KrzakLara/GameVault/actions/workflows/ci.yml?query=branch%3Amain)

The badge shows the outcome of the latest run on `main`; every run, with its logs and the uploaded
E2E reports, is public under [Actions](https://github.com/KrzakLara/GameVault/actions/workflows/ci.yml).
This README does not state a CI result of its own: the figures in [Verified results](#verified-results)
come from the local runs described there.

## Performance results

No load-test figures are published yet. The k6 scenarios in [loadtest/k6](loadtest/k6)
(storefront browsing and search, a checkout burst with order-status polling, and a last-keys
contention race) run with `scripts/loadtest.sh` against the isolated E2E stack only, and finish
with a database check for oversold or double-allocated keys. Results are written to
`loadtest/results/`.

## Public demo

There is no public deployment yet. [docs/deployment.md](docs/deployment.md) compares realistic
hosting options, estimates resources, and lists the security work a public demo needs first
(rate-limited registration, trusted proxies, CSP/HSTS, no published admin credentials, a guarded
demo reset).

## How I used AI

This repository was built with an AI coding assistant (Claude Code, Anthropic) working as the
implementing engineer on written specifications from the repository owner: the owner defined the
goals, phases and non-negotiable constraints (no fabricated results, real MySQL for concurrency
claims, isolated test environments, no pushes), and the assistant wrote the code, tests, scripts
and documentation, and ran every check.

What keeps that honest:
- **Every claim is tied to an executed check.** Test counts, E2E results, Horizon checks and load-test numbers in this README were copied from real runs; failures found on the way (for example a selector that matched the wrong element, or `artisan serve` silently dropping the E2E database setting) were fixed and re-run, not hidden.
- **Verification is automated and reproducible.** Anyone can re-run every result with the commands above.
- **No invented review.** This README does not claim a human code review, security audit or production deployment that did not happen. Review the code before relying on it.

## Known limitations

- Payment and supplier are simulated (`SimulatedKeyProvisioner` waits and can fake an outage); there is no real payment provider or key supplier.
- The delivery e-mail is at-least-once; a duplicate e-mail is possible after a publisher crash, a lost one is not.
- `APP_KEY` rotation requires re-encrypting keys and recomputing fingerprints.
- Leases rely on synchronised clocks across hosts.
- CheapShark prices are USD for PC storefronts and are not comparable with the EUR catalogue prices.
- The local and E2E app server is `php artisan serve` (PHP's built-in server); load numbers describe that setup, not a tuned production deployment.
- The E2E suite runs in Chromium only.

## Future improvements

- Real payment (provider sandbox) with webhooks feeding the same idempotent checkout
- Push updates for order status (Laravel Reverb) instead of polling
- Production container image (PHP-FPM or FrankenPHP/Octane) and load tests against it
- Key rotation tooling (re-encryption, fingerprint migration)
- Cross-browser E2E (Firefox, WebKit) and visual regression snapshots
- Per-title supplier adapters behind `KeyProvisioner`

## Documentation

- [Engineering deep dive](docs/engineering.md): checkout sequence, inventory consistency, fulfillment lifecycle, outbox, security model, trade-offs
- [Architecture and guarantees](docs/architecture.md): every guarantee, where it is enforced, crash analysis, verification
- [Architecture decision records](docs/adr/README.md)
- [Public demo: feasibility, plan and security](docs/deployment.md)
- [End-to-end tests](e2e/README.md)
- [Changelog](CHANGELOG.md)
