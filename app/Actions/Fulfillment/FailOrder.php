<?php

namespace App\Actions\Fulfillment;

use App\Enums\FailureCode;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Models\GameKey;
use App\Models\Order;
use App\Services\Inventory\InventoryAllocator;
use App\Support\Transactions;
use Illuminate\Support\Facades\Log;

/**
 * Moves an in-flight order to "failed" and returns its reservation to stock when that is safe.
 * A failed order never exposes a key: reveal requires status "delivered".
 */
final class FailOrder
{
    public function __construct(private readonly InventoryAllocator $inventory) {}

    /**
     * @param  array<string, mixed>  $meta
     * @param  string|null  $leaseToken  Set when a worker fails its OWN claim: the failure is then
     *                                   fenced and ignored if that worker no longer owns the order.
     *                                   Null (e.g. the queue gave up on a job): the order is failed
     *                                   unless another worker is live on it right now.
     * @return bool Whether the order was failed by this call.
     */
    public function handle(int $orderId, FailureCode $code, array $meta = [], ?string $leaseToken = null): bool
    {
        return Transactions::retrying(function () use ($orderId, $code, $meta, $leaseToken): bool {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if (! $order || ! $order->status->isInFlight()) {
                return false;
            }

            if ($leaseToken !== null) {
                if ($order->status !== OrderStatus::Processing || ! hash_equals((string) $order->lease_token, $leaseToken)) {
                    Log::warning('fulfillment.stale_failure_ignored', ['order_id' => $order->id, 'failure_code' => $code->value]);

                    return false;
                }
            } elseif ($order->hasLiveLease()) {
                // A live worker owns the order: its outcome decides. If it dies, its lease runs out
                // and the job's redelivery or gamevault:recover-stuck takes over.
                Log::warning('fulfillment.failure_deferred_live_lease', ['order_id' => $order->id, 'failure_code' => $code->value]);

                return false;
            }

            $this->failLocked($order, $code, $meta);

            return true;
        });
    }

    /**
     * Caller holds the order row lock and has decided the order must fail.
     *
     * @param  array<string, mixed>  $meta
     */
    public function failLocked(Order $order, FailureCode $code, array $meta = []): void
    {
        $key = GameKey::query()->where('order_id', $order->id)->lockForUpdate()->first();

        // A key whose ciphertext is broken must not go back on sale: it stays reserved against
        // the failed order (quarantined) until an operator investigates. A sold key is never
        // released (release() refuses).
        if ($key && $key->status === KeyStatus::Reserved && $code !== FailureCode::KeyIntegrity) {
            $this->inventory->release($key, $order);
        }

        $order->transitionTo(OrderStatus::Failed, $code, meta: $meta);

        Log::warning('fulfillment.failed', ['order_id' => $order->id, 'failure_code' => $code->value, ...$meta]);
    }
}
