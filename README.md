# GAMEVAULT 🎮

**Game-key marketplace showcase** · PHP / Laravel 13 · Vue 3 · Inertia · MySQL · Tailwind CSS

> Fictional games, simulated payments and synthetic keys. Not affiliated with GameBoost. The code is a portfolio demo, not a production payment processor.

## Why this project exists

Game marketplaces have a deceptively difficult backend problem: when two buyers request the last inventory item, neither should receive the same key. GameVault models the full path from checkout to queued, transaction-safe fulfillment.

### Features
- Searchable, responsive gaming catalogue with Steam / PlayStation / Xbox filters
- Authentication via official Laravel Vue starter kit
- Demo checkout and order statuses (`pending`, `delivered`, `failed`)
- Queued order delivery, InnoDB row locking and idempotent worker
- Encrypted keys at rest, owner-only reveal endpoint, no secret in notification email
- CheapShark price reference (cached 30 min; USD is clearly labeled)
- Pest tests, MySQL CI and Vue production build

## Setup (on a developer machine with network access)

The source package is an **overlay** for the official Laravel Vue starter (not a standalone framework distribution). To make a complete repo:

```bash
composer create-project laravel/vue-starter-kit gamevault
cd gamevault
# Copy all files from this overlay into the gamevault directory, preserving folders.
# Then append the following line to routes/web.php (only once):
# require __DIR__.'/gamevault.php';
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Set up a local MySQL database and configure `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gamevault
DB_USERNAME=gamevault
DB_PASSWORD=your_local_password
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

```bash
php artisan migrate
php artisan db:seed --class=GameVaultSeeder
php artisan serve
npm run dev
# Another terminal:
php artisan queue:work --tries=3
```

Visit `http://localhost:8000/games`. Register and buy a fictional game to test delivery. Refresh My Orders or enable polling for updates.

## Tests

```bash
php artisan test --filter=GameVault
npm run build
```

**Status:** Source generated, **not executed in this environment** (Composer/dependency downloads unavailable). The supplied tests cover sequential non-duplication and idempotence, **not true simultaneous concurrency**. Add a 2-process MySQL concurrency integration scenario before using the word “proven”.

## AI-assisted engineering

AI helped scaffold models, frontend pages and tests. Engineering review focus: order ownership checks, correct transaction boundaries, after-commit event timing, secret handling, deadlock retries and realistic concurrency claims. All AI-produced code must be run, reviewed and corrected before publication as production-ready. This statement describes the generated scaffold, not an assertion that a finished repo was reviewed and verified.

See [architecture](docs/architecture.md) for the fulfillment diagram and tradeoffs.

## Next hardening steps

Concurrent fulfillment integration test using multiple MySQL connections/processes, transactional outbox for guaranteed notification dispatch, stock-aware checkout semantics, request idempotency keys, observability, image assets and browser E2E tests.

### Optional local MySQL via Docker

`docker compose up -d` starts MySQL 8.4 on `127.0.0.1:3307`. Set `.env` DB host `127.0.0.1`, port `3307`, database/user `gamevault`, password `gamevault_local_dev`. These values are for local development only.

### Screenshots to add after verifying the running app

1. Catalogue with filters and item cards
2. Game details and demo checkout
3. Delivered order before/after reveal
4. GitHub Actions green pipeline
