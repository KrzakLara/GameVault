<?php

namespace App\Jobs;

use App\Services\Outbox\OutboxPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Low-latency trigger for the outbox publisher. Losing this job is harmless: the scheduled
 * `outbox:publish` command picks up anything left pending. Running it many times is harmless
 * too: messages are claimed with SKIP LOCKED leases.
 */
class PublishOutboxMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue(config('gamevault.outbox.queue'));
    }

    public function handle(OutboxPublisher $publisher): void
    {
        $publisher->publishBatch();
    }
}
