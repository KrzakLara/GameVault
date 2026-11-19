<?php

/*
 * Worker process for multi-process concurrency tests. NOT a test file.
 *
 * Usage: php tests/Concurrency/worker.php '<json task>'
 *
 * Boots the full application with its own MySQL connection, installs optional race-widening
 * hooks, prepares the scenario, signals "ready" and waits for the barrier, then runs the
 * critical section and prints one JSON line prefixed with @@RESULT@@.
 *
 * Hooks only ever live here, in test code: production classes contain no test switches.
 */

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concurrency\Hooks;
use Tests\Support\Concurrency\Scenarios;

require __DIR__.'/../../vendor/autoload.php';

$task = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$barrier = (string) getenv('GV_BARRIER_DIR');
$index = (int) getenv('GV_WORKER_INDEX');

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(HttpKernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');
if (! $app->environment('testing') || ! str_contains($database, '_test')) {
    fwrite(STDERR, "Refusing to run worker against database [{$database}].\n");
    exit(2);
}

$begun = 0;
$rolledBack = 0;
$lastCommitAt = null;
Event::listen(TransactionBeginning::class, function () use (&$begun) {
    $begun++;
});
Event::listen(TransactionRolledBack::class, function () use (&$rolledBack) {
    $rolledBack++;
});
Event::listen(TransactionCommitted::class, function () use (&$lastCommitAt) {
    $lastCommitAt = microtime(true); // wall clock right after COMMIT returned
});

Hooks::install($task['hooks'] ?? []);

// Connect and prepare BEFORE the barrier, so only the critical section runs after it.
$connectionId = (int) DB::scalar('SELECT CONNECTION_ID()');
$run = Scenarios::prepare((string) $task['scenario'], (array) ($task['args'] ?? []));

if ($barrier !== '') {
    touch("{$barrier}/ready.{$index}");
    $deadline = microtime(true) + 90;
    while (! file_exists("{$barrier}/go")) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "Barrier timeout\n");
            exit(3);
        }
        usleep(200);
    }
}

$begunBefore = $begun;
$rolledBackBefore = $rolledBack;
$startedAt = microtime(true);

try {
    $result = $run();
    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $result = ['exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 500)];
}

$finishedAt = microtime(true);

fwrite(STDOUT, "\n@@RESULT@@".json_encode([
    'ok' => $ok,
    'result' => $result + ['connection_id' => $connectionId, 'pid' => getmypid()],
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
    'transactions_begun' => $begun - $begunBefore,
    'transactions_rolled_back' => $rolledBack - $rolledBackBefore,
    'last_commit_at' => $lastCommitAt,
], JSON_THROW_ON_ERROR).PHP_EOL);

exit(0);
