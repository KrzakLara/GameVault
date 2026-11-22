# Public demo: feasibility, plan and security

Status: **planning document, nothing is deployed.** No hosting has been chosen or paid for, and
the gaps listed under [Before going public](#before-going-public) are not closed yet. This page
says what a public GameVault demo needs, compares realistic ways to host it, and lists the work
and the safeguards required first.

## What the demo needs

GameVault is not a stateless web app. A faithful demo runs the same moving parts as the local
Docker stack:

| Component | Why it is needed | Notes |
| --- | --- | --- |
| PHP 8.4 app server | Laravel + Inertia | Not `php artisan serve` (development only): PHP-FPM behind a web server, or FrankenPHP |
| MySQL 8.x | Orders, keys, ledger, outbox | `FOR UPDATE SKIP LOCKED` and enforced CHECK constraints need MySQL 8.0.16+; tested on 8.4 |
| Redis | Queues, cache, Horizon | Must persist across restarts or queued deliveries are lost (recovery would re-dispatch them after 10 min) |
| Horizon | Delivers keys, publishes e-mails | Long-running process with a supervisor/restart policy |
| Scheduler | Outbox sweep (every minute), stuck-order recovery (every 5 min) | `schedule:work` process or a cron entry calling `schedule:run` |
| HTTPS | Sessions, CSRF, secure cookies | Automatic certificates (Let's Encrypt) |

Memory measured with `docker stats` on the local stack (2026-11-22): MySQL ≈ 650 MB with default
settings, Horizon ≈ 260 MB, app server ≈ 100–120 MB, scheduler ≈ 40 MB, Redis ≈ 10 MB, so about
1.1 GB in total before tuning. A 2 GB server is tight; 4 GB is comfortable. Lowering
`innodb_buffer_pool_size` to 128–256 MB is enough for the demo's data.

## Options

Prices change: the figures below are rough orders of magnitude to compare options, not quotes.
Check current pricing before buying anything.

| Option | Fit | Operations | Rough monthly cost |
| --- | --- | --- | --- |
| **A. One small VPS with Docker Compose and Caddy** | Runs the existing Compose topology almost unchanged; full control over isolation | You patch the OS, back up MySQL, watch disk and memory | ~€5–15 for 2 vCPU / 4 GB at budget providers |
| B. Laravel Forge managing a VPS | Native Laravel setup: nginx + PHP-FPM, MySQL, Redis, Horizon daemon, scheduler cron, certificates | Less hands-on; not Docker-based | Forge subscription plus the VPS |
| C. Docker PaaS (for example Railway, Fly.io, Render) | Works, but MySQL is a self-managed volume or a plugin, and workers/scheduler are separate services | Little server work; usage-based billing across several services | Usage-based; several services add up |
| D. Laravel Cloud | Managed app, queues, scheduler and databases | Least operations | Usage-based; confirm MySQL 8 feature support (SKIP LOCKED, CHECK) first |

**Recommendation: A.** The repository already describes the whole stack as Compose services, the
demo needs every one of them, and a single small VPS keeps cost and moving parts lowest. B is the
choice if you prefer managed Laravel operations over Docker.

## Work required for option A

1. **Production image**: PHP-FPM (or FrankenPHP) with OPcache enabled, built assets baked in, no
   dev dependencies (`composer install --no-dev`, `npm ci && npm run build` in a build stage).
2. **`docker-compose.prod.yml`**: app, Horizon, scheduler, MySQL, Redis and Caddy; MySQL and Redis
   on an internal network only (no published ports); named volumes for MySQL and Redis (with AOF
   persistence); `restart: unless-stopped`; health checks.
3. **Caddy** in front: automatic HTTPS, HTTP→HTTPS redirect, HSTS, request size limits, optional
   per-IP rate limiting.
4. **Release script**: pull, build, `php artisan migrate --force`, `php artisan horizon:terminate`
   (Horizon restarts with the new code), `php artisan config:cache route:cache view:cache`.
5. **Server environment file** created on the server only, never copied from a laptop:
   new `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, own database credentials,
   `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning`, daily log rotation.
6. **Backups** (optional for a resettable demo): nightly `mysqldump` kept for a few days.

## Before going public

Gaps found in the current code (2026-11-22) that a public demo must close first:

| Gap | Risk | Fix |
| --- | --- | --- |
| `POST /register` has no rate limit | Scripted account creation, database growth | `throttle` middleware on registration (or disable registration and offer shared demo accounts) |
| Trusted proxies are not configured | Behind Caddy, Laravel sees HTTP and the proxy's IP: wrong secure-cookie and per-IP limits | Trust the reverse proxy in `bootstrap/app.php` |
| No `Content-Security-Policy` or `Strict-Transport-Security` header | Weaker XSS and downgrade protection | Add both (HSTS at Caddy or in `SecurityHeaders`) |
| Fonts load from `fonts.bunny.net` | Third-party request on every visit; complicates a strict CSP | Self-host the three font families |
| Admin console needs a full admin account | Publishing admin credentials would let anyone import keys, retry, cancel and requeue | Never publish admin credentials. Show the console through screenshots or a recorded walkthrough, or add a read-only demo-admin role (see below) |
| Local demo accounts use the password `password` | Trivially guessable | Never reuse local accounts or passwords; create demo accounts on the server with generated passwords |

Already in place and relevant to a public demo: owner-only order access (404 for others), admin
gate on every admin route, Horizon restricted to admins in every environment, CSRF protection,
rate limits on checkout and key reveal, login throttling, keys encrypted at rest and never logged
or sent in e-mails, generic error pages with `APP_DEBUG=false`, and a request id on every response.

## Abuse and resource exhaustion

- **Purchases**: checkout is rate limited per user and bounded by synthetic stock; a periodic reset
  restores stock. Registration throttling stops one person creating many buyers.
- **Admin operations** (key import, retries, cancellations, outbox requeue): unreachable without an
  admin account, which is never published.
- **Queues**: Horizon's process limits bound concurrency; retries are capped (5 tries, then the
  order fails and its key returns to stock).
- **Traffic floods**: a single small server cannot absorb a real DDoS. Rate limiting at Caddy (or a
  free CDN tier in front) handles casual abuse; for anything more, the demo can be taken offline
  without losing anything real.

## Demo data and reset (proposed, not implemented)

A public demo should start from a known state and return to it on a schedule:

- A `DemoSeeder` built like `E2eSeeder`: fictional titles and synthetic keys, a few customer
  accounts, and orders created through the real checkout and fulfillment actions so that
  delivered, pending and failed orders, inventory movements and outbox history are genuine.
- A `gamevault:demo-reset` command guarded like `e2e:reset`: it refuses to run unless
  `APP_ENV=demo` **and** the database name ends in `_demo`, checked before `migrate:fresh`, with a
  test proving it cannot touch any other database (see `app/Support/E2eGuard.php` and
  `tests/Feature/Security/E2eIsolationTest.php` for the existing pattern).
- A nightly schedule entry for the reset, enabled only in the demo environment.
- A read-only demo-admin role, if a live admin console is wanted: it would see the console but
  every admin action would refuse it on the server, with tests for each action.
