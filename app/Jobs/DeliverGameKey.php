<?php

namespace App\Jobs;

use App\Events\OrderDelivered;
use App\Models\GameKey;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverGameKey implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $order = Order::query()->whereKey($this->orderId)->lockForUpdate()->first();
            if (! $order || $order->status !== 'pending') return; // idempotent job

            // InnoDB locks a concrete available row; unique(order_id) adds defense in depth.
            $key = GameKey::query()->where('game_id', $order->game_id)
                ->where('status', 'available')->orderBy('id')->lockForUpdate()->first();

            if (! $key) {
                $order->update(['status' => 'failed']);
                return;
            }

            $key->update(['status' => 'sold', 'order_id' => $order->id]);
            $order->update(['status' => 'delivered', 'delivered_at' => now()]);
            // Listener runs only after the transaction commits, via event dispatch callback.
            DB::afterCommit(fn () => OrderDelivered::dispatch($order->fresh()));
        }, 5); // retry deadlocks
    }
}
