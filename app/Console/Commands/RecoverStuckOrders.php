<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for lost or abandoned fulfillment. An order is stuck when it has made no progress
 * for the stuck threshold (gamevault.fulfillment.stuck_after_minutes):
 *  - "pending" orders untouched for that long: their queue message was probably lost
 *    (Redis flushed, dispatch failed after commit);
 *  - "processing" orders whose lease expired that long ago: their worker crashed or hung and
 *    no queue retry has picked the order up since.
 *
 * A recently expired lease is not a sign of trouble: after a transient error the worker
 * relinquishes its lease and the queue retries the job after its backoff. Re-dispatching such
 * an order would start a second retry chain next to the scheduled one (more calls to a failing
 * supplier, retries ahead of the backoff schedule), so the threshold must stay longer than any
 * gap between retries (asserted in ConfigInvariantsTest).
 *
 * Re-dispatching is always safe: FulfillOrder is idempotent, a live lease makes a duplicate
 * job a no-op, and the per-cycle attempt cap guarantees a stuck order eventually fails and
 * releases its reserved key instead of holding stock forever.
 */
class RecoverStuckOrders extends Command
{
    protected $signature = 'gamevault:recover-stuck {--minutes= : Override the stuck threshold} {--dry-run}';

    protected $description = 'Re-dispatch fulfillment for lost pending orders and long-expired leases';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('gamevault.fulfillment.stuck_after_minutes'));
        $stuckBefore = now()->subMinutes($minutes);

        $ids = Order::query()
            ->where(fn (Builder $q) => $q->where('status', OrderStatus::Pending)->where('updated_at', '<', $stuckBefore))
            ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Processing)->where('lease_expires_at', '<', $stuckBefore))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');

        if (! $this->option('dry-run')) {
            $ids->each(fn (int $id) => DeliverGameKey::dispatch($id));
        }

        if ($ids->isNotEmpty()) {
            Log::warning('fulfillment.recovered_stuck', ['count' => $ids->count(), 'dry_run' => (bool) $this->option('dry-run')]);
        }

        $this->info(($this->option('dry-run') ? 'Would re-dispatch ' : 'Re-dispatched ')."{$ids->count()} order(s).");

        return self::SUCCESS;
    }
}
