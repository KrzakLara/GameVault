<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for lost or abandoned fulfillment:
 *  - "pending" orders untouched for a while: their queue message was probably lost
 *    (Redis flushed, dispatch failed after commit);
 *  - "processing" orders whose lease has expired: their worker crashed or hung.
 *
 * Re-dispatching is always safe: FulfillOrder is idempotent, a live lease makes a duplicate
 * job a no-op, and the per-cycle attempt cap guarantees a stuck order eventually fails and
 * releases its reserved key instead of holding stock forever.
 */
class RecoverStuckOrders extends Command
{
    protected $signature = 'gamevault:recover-stuck {--minutes= : Override the pending threshold} {--dry-run}';

    protected $description = 'Re-dispatch fulfillment for lost pending orders and orders with an expired lease';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('gamevault.fulfillment.stuck_after_minutes'));

        $ids = Order::query()
            ->where(fn (Builder $q) => $q->where('status', OrderStatus::Pending)->where('updated_at', '<', now()->subMinutes($minutes)))
            ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Processing)->where('lease_expires_at', '<', now()))
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
