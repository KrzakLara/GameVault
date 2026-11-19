# GAMEVAULT 🎮

**Game-key marketplace showcase** · PHP 8.4 / Laravel 12 · Vue 3 · Inertia · MySQL 8.4 · Redis + Horizon

> Fictional games, simulated payments and synthetic keys. Not affiliated with any real store. The code is a portfolio demo, not a production payment processor.

## Why this project exists

Game marketplaces have a deceptively difficult backend problem: when twenty buyers request the last key at the same moment, exactly one may get it, nobody may be told "sold out" while it could still be theirs, and queue jobs that run twice or crash half-way must never deliver a key twice or lose one. GameVault models the full path from checkout to queued, transaction-safe fulfillment and verifies it with real multi-process tests on MySQL.

### Backend features
- Idempotent checkout (per user + idempotency key, enforced by a unique index), server-side price snapshot, synchronous stock reservation
- Concurrency-safe inventory: `FOR UPDATE SKIP LOCKED` with a verified-stockout fallback (no false "sold out")
- Fulfillment with leases and fencing tokens: duplicate jobs are no-ops, crashed workers are taken over, stale workers cannot finalize
- Transactional outbox with SKIP LOCKED leases, fencing, exponential backoff and dead letters
- 17 MySQL CHECK constraints plus composite foreign keys for inventory ownership
- Owner-only, rate-limited, audited key reveal (POST); encrypted keys at rest; no key in e-mails
- Admin area (orders, retry/cancel, key import, outbox) behind a server-side gate; audit log
- Request-ID correlation across HTTP, logs, queued jobs and audit entries

See **[docs/architecture.md](docs/architecture.md)** for the guarantees, how each is enforced, and the failure matrix.

## Running locally (Docker)

```bash
cp .env.example .env
docker compose up -d mysql redis
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan db:seed --class=GameVaultSeeder
docker compose run --rm app npm ci
docker compose run --rm app npm run build
docker compose up -d app                       # http://localhost:8000/games
docker compose --profile workers up -d         # Horizon + scheduler (fulfillment, outbox, recovery)
docker compose run --rm app php artisan gamevault:admin you@example.com   # admin rights (CLI only)
```

## Tests and quality checks

```bash
docker compose run --rm app php artisan test                          # everything (Unit, Feature, Integration)
docker compose run --rm app php artisan test --testsuite=Integration  # MySQL locking, multi-process concurrency, migrations
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
```

The suite runs on MySQL 8.4 only, against the dedicated `gamevault_test` database. `phpunit.xml` forces that database even inside the app container (whose environment comes from `.env`), and `Tests\TestCase` refuses to run against any database whose name lacks `_test`, so tests can never wipe development data.

Concurrency tests use independent PHP processes, each with its own MySQL connection, released simultaneously by a barrier (see docs/architecture.md, "Verification").

## Honest limitations

Simulated payment and supplier; at-least-once delivery for the notification e-mail; APP_KEY rotation needs a re-encryption step; the frontend pages are functional but minimal (the UI is the next phase).

## AI-assisted engineering

AI helped scaffold and harden this code. Every claim in this README and in docs/architecture.md is backed by a test that was executed against MySQL 8.4 in Docker; concurrency claims come from multi-process tests, not sequential ones.
