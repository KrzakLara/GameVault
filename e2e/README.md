# End-to-end tests (Playwright)

Twenty browser journeys against the **real application**: PHP server, MySQL, Redis, Horizon,
scheduler, nothing mocked except the external CheapShark API (a local stub, see below).

```sh
scripts/e2e.sh                      # build, reset the E2E database, start the stack, run all journeys
scripts/e2e.sh e2e/admin.spec.ts    # extra arguments go to `playwright test`
E2E_KEEP_RUNNING=1 scripts/e2e.sh   # leave the stack up afterwards: http://127.0.0.1:8001
```

Reports: `playwright-report/` (HTML), `test-results/results.json`, traces and screenshots of
failures in `test-results/`, application logs in `storage/logs/e2e/`.

## Isolation: it cannot touch development data

| Resource | Development                | E2E stack                                               |
| -------- | -------------------------- | ------------------------------------------------------- |
| MySQL    | `gamevault`                | `gamevault_e2e`                                         |
| Redis    | databases 0/1              | databases 2/3, key prefix `gamevault_e2e_`              |
| Logs     | `storage/logs/laravel.log` | `storage/logs/e2e/`                                     |
| URL      | http://127.0.0.1:8000      | http://127.0.0.1:8001 (http://localhost:8000 in Docker) |

- `app/Support/E2eGuard.php` makes the app refuse to boot with `APP_ENV=e2e` unless the database
  name ends in `_e2e`, and `php artisan e2e:reset` checks the same thing **before** it runs
  `migrate:fresh`. Both are covered by `tests/Feature/Security/E2eIsolationTest.php`.
- The E2E server runs `php artisan serve --no-reload`. Without `--no-reload`, `artisan serve`
  strips compose-provided variables from the server process, which would then read
  `DB_DATABASE` from `.env`, i.e. the development database.
- `scripts/e2e.sh` deletes only Redis keys with the `gamevault_e2e_` prefix in databases 2 and 3.

## Fixtures

`database/seeders/E2eSeeder.php` builds the same catalogue on every run: 27 fictional games,
deterministic synthetic keys (`GVE2E-XXXXX-XXXXX`) and one user per journey, so journeys do not
depend on each other. Order history (which drives "Trending") and the failed order the admin
retries are created through the real checkout and fulfillment actions. All passwords are
`password`.

CheapShark is replaced by `e2e/stubs/cheapshark.php`: `e2e-deals` returns two fixture offers,
`e2e-outage` returns 503. Those prices are test fixtures, not market data.

## Journeys

| #     | Spec                 | Journey                                                                                       |
| ----- | -------------------- | --------------------------------------------------------------------------------------------- |
| 1–4   | `storefront.spec.ts` | storefront, catalogue search, platform filter, game detail                                    |
| 5–10  | `purchase.spec.ts`   | sign in, simulated purchase, My Orders, fulfillment, key reveal, copy                         |
| 11–16 | `admin.spec.ts`      | admin sign-in, dashboard, order filters, failed fulfillment, retry, non-admin blocked         |
| 17–20 | `errors.spec.ts`     | sold out (incl. last-key race), pricing API outage, checkout rate limit, duplicate submission |
| —     | `navigation.spec.ts` | release-gate check (not one of the 20): no internal link on the main pages returns 4xx/5xx    |

Waiting is done with Playwright's auto-waiting assertions (for example, fulfillment is awaited by
watching the order page's own status polling), never with fixed sleeps. Retries are disabled so
a flaky journey fails visibly.
