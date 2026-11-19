<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs tasks in N independent OS processes and releases them at the same instant.
 *
 * Each worker (tests/Concurrency/worker.php) boots the full Laravel application and opens its
 * OWN MySQL connection, so the database sees N genuinely concurrent sessions. Workers do all
 * their setup (bootstrapping, connecting, loading fixtures) first, report "ready", then spin on
 * a "go" file. The parent creates "go" only once every worker is ready, so the critical
 * sections start within ~1 ms of each other. Each result carries wall-clock start/finish
 * times so a test can assert that the critical sections really overlapped.
 */
final class ConcurrentRun
{
    /**
     * @param  list<array{scenario: string, args?: array<string, mixed>, hooks?: array<string, mixed>}>  $tasks
     * @param  array<string, string|int>  $env  Extra environment variables for every worker.
     * @return list<WorkerResult>
     */
    public static function run(array $tasks, array $env = [], int $timeoutSeconds = 120): array
    {
        return self::stage($tasks, $env, $timeoutSeconds)->go()->results();
    }

    /**
     * Start the workers and return once ALL of them are booted and parked at the barrier.
     * Call go() on the result to release them.
     *
     * @param  list<array{scenario: string, args?: array<string, mixed>, hooks?: array<string, mixed>}>  $tasks
     * @param  array<string, string|int>  $env
     */
    public static function stage(array $tasks, array $env = [], int $timeoutSeconds = 120): StagedRun
    {
        $dir = self::scratchDirectory('barrier');

        /** @var array<int, Process> $processes */
        $processes = [];

        try {
            foreach (array_values($tasks) as $i => $task) {
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Concurrency/worker.php'), json_encode($task, JSON_THROW_ON_ERROR)],
                    base_path(),
                    self::environment($dir, $i, $env),
                    null,
                    $timeoutSeconds,
                );
                $process->start();
                $processes[$i] = $process;
            }

            self::awaitBarrier($dir, $processes);

            return new StagedRun($dir, $processes);
        } catch (\Throwable $e) {
            (new StagedRun($dir, $processes))->cleanUp();

            throw $e;
        }
    }

    /**
     * Start one long-running worker without a barrier (e.g. a process that will be killed).
     *
     * @param  array{scenario: string, args?: array<string, mixed>, hooks?: array<string, mixed>}  $task
     * @param  array<string, string|int>  $env
     */
    public static function start(array $task, array $env = [], int $timeoutSeconds = 120): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/worker.php'), json_encode($task, JSON_THROW_ON_ERROR)],
            base_path(),
            self::environment(null, 0, $env),
            null,
            $timeoutSeconds,
        );
        $process->start();

        return $process;
    }

    /**
     * A fresh directory on the local (container) filesystem shared by the test process and its
     * workers. Deliberately not under storage/: that is a bind mount on Docker Desktop, where
     * file polling is slow and cross-process file semantics are weaker.
     */
    public static function scratchDirectory(string $purpose): string
    {
        $dir = sys_get_temp_dir().'/gamevault-tests/'.$purpose.'-'.Str::ulid();
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * Run the same artisan command (e.g. real queue:work workers) in N parallel processes and
     * wait for all of them.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|int>  $env
     * @return list<Process>
     */
    public static function artisan(int $processes, array $arguments, array $env = [], int $timeoutSeconds = 120): array
    {
        $running = [];
        for ($i = 0; $i < $processes; $i++) {
            $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments], base_path(), self::environment(null, $i, $env), null, $timeoutSeconds);
            $process->start();
            $running[] = $process;
        }

        foreach ($running as $process) {
            $process->wait();
        }

        return $running;
    }

    /**
     * Environment shared by every worker: same database and APP_KEY as the test process,
     * testing-only drivers, and no inherited development settings.
     *
     * @param  array<string, string|int>  $extra
     * @return array<string, string>
     */
    private static function environment(?string $barrierDir, int $index, array $extra): array
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        return array_map('strval', [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $db['host'] ?? '',
            'DB_PORT' => $db['port'] ?? '',
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'] ?? '',
            'DB_PASSWORD' => $db['password'] ?? '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'warning',
            'GAMEVAULT_FULFILLMENT_LATENCY_MS' => '0',
            'CHEAPSHARK_ENABLED' => 'false',
            'GV_BARRIER_DIR' => $barrierDir ?? '',
            'GV_WORKER_INDEX' => (string) $index,
            ...$extra,
        ]);
    }

    /** @param  array<int, Process>  $processes */
    private static function awaitBarrier(string $dir, array $processes): void
    {
        $deadline = microtime(true) + 90;

        while (true) {
            $ready = 0;
            foreach ($processes as $i => $process) {
                if (file_exists("{$dir}/ready.{$i}")) {
                    $ready++;
                } elseif (! $process->isRunning()) {
                    throw new RuntimeException("Worker {$i} exited before reaching the barrier.\nSTDOUT: {$process->getOutput()}\nSTDERR: {$process->getErrorOutput()}");
                }
            }

            if ($ready === count($processes)) {
                return;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException("Only {$ready}/".count($processes).' workers reached the barrier in time.');
            }

            usleep(5_000);
        }
    }

    /**
     * True when every critical section started before any of them finished, i.e. they were
     * all in flight at the same moment.
     *
     * @param  list<WorkerResult>  $results
     */
    public static function allOverlapped(array $results): bool
    {
        $latestStart = max(array_map(fn (WorkerResult $r) => $r->startedAt, $results));
        $earliestEnd = min(array_map(fn (WorkerResult $r) => $r->finishedAt, $results));

        return $latestStart < $earliestEnd;
    }
}
