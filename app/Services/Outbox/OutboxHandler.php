<?php

namespace App\Services\Outbox;

use App\Models\OutboxMessage;

interface OutboxHandler
{
    /** Throw to signal a retryable failure. Must tolerate being called more than once. */
    public function handle(OutboxMessage $message): void;
}
