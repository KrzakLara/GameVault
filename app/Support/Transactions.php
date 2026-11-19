<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a closure in a database transaction and retries the WHOLE transaction when InnoDB aborts
 * it because of a deadlock (1213) or a lock-wait timeout (1205).
 *
 * Compared with DB::transaction($callback, $attempts), each retry waits a short randomised delay
 * so the colliding transactions do not immediately collide again, and every retry is logged.
 *
 * Retrying is only valid at the outermost level: InnoDB rolls back the entire transaction on a
 * deadlock, so inside a caller's transaction the error is rethrown for the caller to handle.
 * When attempts are exhausted the exception propagates; the HTTP layer maps it to a 503 that is
 * safe to retry because checkout is idempotent.
 */
final class Transactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function retrying(Closure $callback, int $attempts = 3): mixed
    {
        if (DB::transactionLevel() > 0) {
            return DB::transaction($callback);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (Throwable $e) {
                if ($attempt >= $attempts || ! self::isConcurrencyError($e)) {
                    throw $e;
                }

                Log::notice('db.transaction_retry', [
                    'attempt' => $attempt,
                    'mysql_error' => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null,
                ]);

                usleep(random_int(5_000, 20_000) * $attempt);
            }
        }
    }

    public static function isConcurrencyError(Throwable $e): bool
    {
        $detector = app()->bound(ConcurrencyErrorDetectorContract::class)
            ? app(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector;

        return $detector->causedByConcurrencyError($e);
    }
}
