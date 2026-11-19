<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\KeyStatus;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Support\KeyFingerprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only code path that changes a key's status or order linkage.
 *
 * Every method must run inside a database transaction. Methods that touch an order's key must
 * be called while holding that order's row lock. Lock order everywhere: games (shared) ->
 * orders -> game_keys, so no two code paths can wait on each other in a cycle.
 *
 * Integrity is enforced in layers; the database has the final word:
 *  1. Row locks: a selected key row stays locked FOR UPDATE until commit.
 *  2. UNIQUE(game_keys.order_id): one order can never own two keys.
 *  3. CHECK game_keys_status_matches_order: status and order linkage always agree (MySQL).
 *  4. FK (order_id, game_id) -> orders(id, game_id): a key only ever belongs to an order for
 *     the same game (MySQL).
 */
final class InventoryAllocator
{
    /**
     * Reserve one available key for the order. Returns null only when the game is sold out.
     *
     * Idempotent: an order that already holds a key gets that key back.
     *
     * @param  bool  $newOrder  The order was inserted in the current transaction, so it cannot
     *                          hold a key yet. Skipping the lookup avoids a locking read of an
     *                          absent index value (a gap lock under REPEATABLE READ).
     */
    public function reserve(Order $order, ?int $actorId = null, bool $newOrder = false): ?GameKey
    {
        $this->assertInTransaction();

        if (! $newOrder) {
            $existing = GameKey::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
        }

        $key = $this->lockAvailableKey($order->game_id);
        if (! $key) {
            return null;
        }

        $key->forceFill(['status' => KeyStatus::Reserved, 'order_id' => $order->id, 'reserved_at' => now()])->save();
        $this->record($key, InventoryMovementType::Reserved, $order, $actorId);

        return $key;
    }

    /**
     * Lock one available key of the game, or return null when the game is genuinely sold out.
     *
     *  1. Fast path, FOR UPDATE SKIP LOCKED: concurrent buyers take DIFFERENT keys instead of
     *     queueing on the same row, so a popular title has no lock convoy.
     *  2. Finding nothing unlocked does not yet mean "sold out". The remaining keys may be held by
     *     transactions that will roll back (a failed checkout, a deadlock victim). Reporting
     *     "sold out" then would be a false stockout, so it is only reported when a plain read of
     *     committed data confirms no key is available.
     *  3. Otherwise wait on those locks (plain FOR UPDATE, bounded by innodb_lock_wait_timeout).
     *     If the holders commit, their keys no longer match and the result is a true stockout;
     *     if one rolls back, its key is returned.
     *
     * Step 3 only ever waits for a holder that is itself never waiting for us: reservers hold
     * key locks, waiters hold none, so this cannot form a deadlock cycle.
     */
    private function lockAvailableKey(int $gameId): ?GameKey
    {
        $key = $this->availableKeys($gameId)->lock('for update skip locked')->first();
        if ($key) {
            return $key;
        }

        if (! $this->availableKeys($gameId)->exists()) {
            return null;
        }

        return $this->availableKeys($gameId)->lockForUpdate()->first();
    }

    /**
     * Walks the (game_id, status, id) index in id order, so a locking read with LIMIT 1 locks at
     * most the row it returns. Forced: with stale statistics (e.g. computed before stock was
     * imported) the optimizer otherwise left this index, which can make one buyer lock rows it
     * does not take. Guarded by tests/Integration/Database/QueryPlanTest.
     *
     * @return Builder<GameKey>
     */
    private function availableKeys(int $gameId): Builder
    {
        return GameKey::query()
            ->forceIndex('game_keys_game_id_status_id_index')
            ->where('game_id', $gameId)
            ->where('status', KeyStatus::Available)
            ->orderBy('id');
    }

    public function markSold(GameKey $key, Order $order): void
    {
        $this->assertInTransaction();

        if ($key->status !== KeyStatus::Reserved || $key->order_id !== $order->id) {
            throw new LogicException("Key {$key->id} is not reserved for order {$order->id}.");
        }

        $key->forceFill(['status' => KeyStatus::Sold, 'sold_at' => now()])->save();
        $this->record($key, InventoryMovementType::Sold, $order);
    }

    /** Return a reserved (never a sold) key to stock. */
    public function release(GameKey $key, Order $order, ?int $actorId = null): void
    {
        $this->assertInTransaction();

        if ($key->status !== KeyStatus::Reserved || $key->order_id !== $order->id) {
            throw new LogicException("Key {$key->id} is not reserved for order {$order->id}.");
        }

        $key->forceFill(['status' => KeyStatus::Available, 'order_id' => null, 'reserved_at' => null])->save();
        $this->record($key, InventoryMovementType::Released, $order, $actorId);
    }

    /**
     * Add new keys to stock. Codes are compared by keyed fingerprint (the ciphertext is
     * randomised); codes already in stock, for any game, are skipped and counted.
     *
     * A concurrent import of the same code is stopped by UNIQUE(code_hash): the transaction
     * fails as a whole and nothing is imported twice.
     *
     * @param  list<string>  $codes
     * @return array{imported: int, duplicates: int}
     */
    public function import(Game $game, array $codes, ?int $actorId = null): array
    {
        $this->assertInTransaction();

        $byHash = [];
        foreach ($codes as $code) {
            $byHash[KeyFingerprint::of($code)] ??= KeyFingerprint::normalize($code);
        }

        $existing = GameKey::query()->whereIn('code_hash', array_keys($byHash))->pluck('code_hash')->flip();
        $imported = 0;

        foreach ($byHash as $hash => $code) {
            if ($existing->has($hash)) {
                continue;
            }

            $key = GameKey::create(['game_id' => $game->id, 'code' => $code]);
            $this->record($key, InventoryMovementType::Imported, null, $actorId);
            $imported++;
        }

        return ['imported' => $imported, 'duplicates' => count($codes) - $imported];
    }

    private function record(GameKey $key, InventoryMovementType $type, ?Order $order, ?int $actorId = null): void
    {
        InventoryMovement::create([
            'game_id' => $key->game_id,
            'game_key_id' => $key->id,
            'order_id' => $order?->id,
            'type' => $type,
            'actor_id' => $actorId,
        ]);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Inventory changes must run inside a database transaction.');
        }
    }
}
