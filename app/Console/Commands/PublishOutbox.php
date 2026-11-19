<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxPublisher;
use Illuminate\Console\Command;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--requeue-dead-letters : Move dead-lettered messages back to pending first (audited)}';

    protected $description = 'Publish due transactional-outbox messages (also reclaims expired leases)';

    public function handle(OutboxPublisher $publisher): int
    {
        if ($this->option('requeue-dead-letters')) {
            $this->info('Requeued '.$publisher->requeueDeadLetters(actor: null).' dead-lettered message(s).');
        }

        $stats = $publisher->publishBatch();
        $this->info(sprintf(
            'published=%d retried=%d dead_lettered=%d stale=%d',
            $stats['published'], $stats['retried'], $stats['dead_lettered'], $stats['stale'],
        ));

        return self::SUCCESS;
    }
}
