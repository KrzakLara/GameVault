<?php

namespace App\Console\Commands;

use App\Models\GameKey;
use App\Support\E2eGuard;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\JobRepository;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;

/**
 * Proves that no plaintext key leaked: decrypts every key in the E2E database and searches log
 * files, failed jobs, Horizon job payloads, the audit log, order events and outbox payloads for
 * any of them. Reports only masked codes. E2E environment only (it decrypts every key).
 */
#[AsCommand(name: 'gamevault:scan-key-leaks')]
class ScanKeyLeaks extends Command
{
    protected $signature = 'gamevault:scan-key-leaks {paths* : Log files or directories to search}';

    protected $description = 'Search logs, failed jobs, Horizon payloads and audit data for plaintext keys (E2E environment only)';

    public function handle(JobRepository $jobs): int
    {
        try {
            E2eGuard::assertIsolated();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $codes = [];
        $undecryptable = 0;
        foreach (GameKey::query()->select(['id', 'code'])->lazyById(500) as $key) {
            try {
                $codes[] = (string) $key->getAttribute('code'); // decrypts (encrypted cast)
            } catch (DecryptException) {
                $undecryptable++;
            }
        }
        if ($codes === []) {
            $this->error('No keys to search for: is this the seeded E2E database?');

            return self::FAILURE;
        }

        // Search for the code as stored, and in the normalised form without separators.
        $needles = array_values(array_unique([...$codes, ...array_map(fn (string $c) => str_replace('-', '', $c), $codes)]));
        $leaks = [];
        $sources = [];

        foreach ($this->files() as $file) {
            $content = (string) file_get_contents($file->getPathname());
            $sources[] = $file->getFilename().' ('.number_format(strlen($content)).' bytes)';
            $this->search($content, $file->getPathname(), $needles, $leaks);
        }

        $tables = [
            'failed_jobs' => ['payload', 'exception'],
            'audit_logs' => ['meta'],
            'order_events' => ['meta'],
            'outbox_messages' => ['payload', 'last_error'],
            'jobs' => ['payload'],
        ];
        foreach ($tables as $table => $columns) {
            $rows = 0;
            foreach (DB::table($table)->select(['id', ...$columns])->lazyById(500) as $row) {
                $rows++;
                $this->search(implode("\n", array_map(fn (string $c) => (string) ($row->{$c} ?? ''), $columns)), "{$table}#{$row->id}", $needles, $leaks);
            }
            $sources[] = "{$table} ({$rows} rows)";
        }

        $horizon = 0;
        foreach (['getRecent', 'getFailed', 'getCompleted', 'getPending'] as $method) {
            foreach ($jobs->{$method}() as $job) {
                $horizon++;
                $this->search(json_encode($job) ?: '', "horizon:{$job->id}", $needles, $leaks);
            }
        }
        $sources[] = "Horizon job records ({$horizon})";

        $this->line('Keys checked: '.count($codes).($undecryptable ? " ({$undecryptable} undecryptable)" : ''));
        $this->line('Searched: '.implode(', ', $sources));

        if ($leaks !== []) {
            $this->components->error(count($leaks).' plaintext key occurrence(s) found:');
            foreach (array_slice($leaks, 0, 20) as [$where, $masked]) {
                $this->line("  {$where}: {$masked}");
            }

            return self::FAILURE;
        }

        $this->components->info('No plaintext key found in any searched source.');

        return self::SUCCESS;
    }

    /** @return iterable<SplFileInfo> */
    private function files(): iterable
    {
        foreach ((array) $this->argument('paths') as $path) {
            $path = str_starts_with($path, '/') ? $path : base_path($path);
            if (is_file($path)) {
                yield new SplFileInfo($path);
            } elseif (is_dir($path)) {
                yield from (new Finder)->files()->in($path)->name('*.log');
            } else {
                throw new RuntimeException("No such file or directory: {$path}");
            }
        }
    }

    /**
     * @param  list<string>  $needles
     * @param  list<array{string, string}>  $leaks
     */
    private function search(string $haystack, string $where, array $needles, array &$leaks): void
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                $leaks[] = [$where, substr($needle, 0, 6).str_repeat('*', max(0, strlen($needle) - 6))];
            }
        }
    }
}
