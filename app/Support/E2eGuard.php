<?php

namespace App\Support;

use RuntimeException;

/**
 * Keeps the Playwright stack away from real data. The e2e environment may only ever talk to a
 * database whose name ends in _e2e, and E2E fixtures may only be (re)built there.
 *
 * Checked at boot whenever APP_ENV=e2e (AppServiceProvider), so a misconfigured process fails
 * loudly instead of silently using the development database. One real way that happens:
 * `php artisan serve` without --no-reload strips compose-provided variables from the server
 * process, which then falls back to the DB_DATABASE in .env.
 */
final class E2eGuard
{
    public static function assertIsolated(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! app()->environment('e2e') || ! str_ends_with($database, '_e2e')) {
            throw new RuntimeException(sprintf(
                'Refusing to use database "%s" in environment "%s": the E2E stack requires APP_ENV=e2e and a database whose name ends in _e2e.',
                $database, app()->environment(),
            ));
        }
    }
}
