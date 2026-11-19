<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

class OutboxStatus extends Command
{
    protected $signature = 'outbox:status {--json : Machine-readable output for monitoring}';

    protected $description = 'Show transactional-outbox health: counts per status, oldest due message, expired leases';

    public function handle(OutboxPublisher $publisher): int
    {
        $stats = $publisher->stats();

        if ($this->option('json')) {
            $this->line((string) json_encode($stats, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['status', 'count'], collect($stats['counts'])->map(fn (int $n, string $s) => [$s, $n])->values()->all());
        $this->line('Oldest due pending message: '.($stats['oldest_due_seconds'] === null ? 'none' : "{$stats['oldest_due_seconds']} s"));
        $this->line("Expired leases (publisher died): {$stats['expired_leases']}");

        return self::SUCCESS;
    }
}
