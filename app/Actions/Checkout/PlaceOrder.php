<?php

namespace App\Actions\Checkout;

use App\Exceptions\CheckoutException;
use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\Order;
use App\Models\User;
use App\Services\Inventory\InventoryAllocator;
use App\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Accepts a simulated checkout: creates the order, reserves a key and queues fulfillment, all or
 * nothing. No payment is processed.
 *
 * Consistency model:
 *  - Idempotent per (user, idempotency key). The arbiter is UNIQUE(user_id, idempotency_key),
 *    not the SELECT below: when identical requests race, InnoDB makes the second INSERT wait on
 *    the first one's index entry; it then fails with a duplicate-key error and replays the
 *    winner's committed order.
 *  - The same key with a different payload is rejected (request fingerprint mismatch).
 *  - Only an accepted checkout consumes its key: a rejected one (sold out, inactive, rolled
 *    back) leaves no row behind, so retrying it with the same key is safe and may succeed.
 *  - The price is snapshotted from the committed game row inside the transaction; the client
 *    never sends a price.
 *  - Stock is decided synchronously: a buyer who cannot get a key gets no order (409), and
 *    "sold out" is only reported when it is true (see InventoryAllocator::lockAvailableKey).
 *  - The fulfillment job is dispatched after commit, so a worker never sees an uncommitted order
 *    and a rolled-back checkout dispatches nothing.
 */
final class PlaceOrder
{
    public function __construct(private readonly InventoryAllocator $inventory) {}

    public function handle(User $user, Game $game, string $idempotencyKey): CheckoutResult
    {
        $fingerprint = self::fingerprint($game);

        // Fast path for a retry of a completed request. Never relied on for correctness.
        if ($existing = $this->findExisting($user, $idempotencyKey)) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $order = Transactions::retrying(function () use ($user, $game, $idempotencyKey, $fingerprint): Order {
                // Locking read of the committed row: the snapshot price and the availability
                // check cannot be stale, and a concurrent admin edit of the game waits for us.
                $current = Game::query()->whereKey($game->id)->sharedLock()->first();
                if (! $current || ! $current->is_active) {
                    throw CheckoutException::unavailable();
                }

                $order = Order::create([
                    'user_id' => $user->id,
                    'game_id' => $current->id,
                    'price_cents' => $current->price_cents,
                    'currency' => $current->currency,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                if (! $this->inventory->reserve($order, $user->id, newOrder: true)) {
                    throw CheckoutException::outOfStock(); // rolls back the order row
                }

                $order->recordEvent('checkout_accepted', $user->id, ['price_cents' => $order->price_cents]);

                DeliverGameKey::dispatch($order->id)->afterCommit();

                return $order->refresh(); // the persisted row, including database defaults
            }, attempts: (int) config('gamevault.checkout.transaction_attempts', 5));
        } catch (UniqueConstraintViolationException $e) {
            // Lost the race against an identical in-flight request, which has now committed.
            $existing = $this->findExisting($user, $idempotencyKey) ?? throw $e;

            return $this->replay($existing, $fingerprint);
        }

        Log::info('checkout.accepted', ['order_id' => $order->id, 'game_id' => $game->id, 'user_id' => $user->id]);

        return new CheckoutResult($order, replayed: false);
    }

    /**
     * Canonical description of what the request asks for. Quantity is fixed at 1 today; it is
     * part of the fingerprint so a future multi-quantity checkout cannot reuse an old key.
     */
    public static function fingerprint(Game $game): string
    {
        return hash('sha256', json_encode(['v' => 1, 'game_id' => $game->id, 'quantity' => 1], JSON_THROW_ON_ERROR));
    }

    private function findExisting(User $user, string $idempotencyKey): ?Order
    {
        return Order::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
    }

    private function replay(Order $existing, string $fingerprint): CheckoutResult
    {
        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            Log::notice('checkout.idempotency_conflict', ['order_id' => $existing->id]);

            throw CheckoutException::idempotencyConflict();
        }

        Log::info('checkout.replayed', ['order_id' => $existing->id]);

        return new CheckoutResult($existing, replayed: true);
    }
}
