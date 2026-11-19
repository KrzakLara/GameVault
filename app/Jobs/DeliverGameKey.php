<?php

namespace App\Jobs;

use App\Actions\Fulfillment\FailOrder;
use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\FailureCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Queue entry point for fulfillment. The queue guarantees at-least-once delivery only, so all
 * correctness lives in FulfillOrder (idempotent per order, lease + fencing token).
 *
 * Failure handling:
 *  - An exception (transient supplier/DB error) is retried with exponential backoff; the worker
 *    relinquishes its lease first, so the retry can take over immediately.
 *  - A timeout kills the worker process; the job stays reserved and the queue redelivers it after
 *    retry_after (90 s), by which time the dead worker's lease (60 s) has expired.
 *  - Once tries are exhausted, failed() fails the order and returns the key to stock.
 */
class DeliverGameKey implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Must stay below the lease (60 s), which stays below the queue's retry_after (90 s). */
    public int $timeout = 30;

    /** Let the queue redeliver a timed-out attempt instead of failing the order outright. */
    public bool $failOnTimeout = false;

    public function __construct(public int $orderId)
    {
        $this->onQueue(config('gamevault.fulfillment.queue'));
    }

    /** @return list<int> Exponential backoff (x3) in seconds between attempts. */
    public function backoff(): array
    {
        return [5, 15, 45, 135];
    }

    public function handle(FulfillOrder $fulfill): void
    {
        Context::add('order_id', $this->orderId);
        Context::add('job_id', $this->job?->getJobId());

        // Busy / AlreadyFinal / Superseded are normal outcomes under at-least-once delivery.
        $fulfill->handle($this->orderId);
    }

    /** Retries exhausted. Fails the order (releasing its key) unless a live worker owns it. */
    public function failed(?Throwable $exception): void
    {
        app(FailOrder::class)->handle($this->orderId, FailureCode::AttemptsExhausted, [
            'exception' => $exception ? class_basename($exception) : null,
        ]);
    }
}
