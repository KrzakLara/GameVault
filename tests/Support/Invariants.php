<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Database-wide business invariants, checked with plain SQL after a test. Each query returns the
 * offending rows, so a failure message says exactly what is wrong.
 */
final class Invariants
{
    public static function assertAll(): void
    {
        self::assertInventoryConsistent();
        self::assertOutboxConsistent();
    }

    public static function assertInventoryConsistent(): void
    {
        $violations = [
            'order holding more than one key' => DB::select(
                'SELECT order_id, COUNT(*) n FROM game_keys WHERE order_id IS NOT NULL GROUP BY order_id HAVING n > 1'),

            'key linked to an order of another game' => DB::select(
                'SELECT k.id FROM game_keys k JOIN orders o ON o.id = k.order_id WHERE o.game_id <> k.game_id'),

            'delivered order without exactly one sold key' => DB::select(
                "SELECT o.id FROM orders o LEFT JOIN game_keys k ON k.order_id = o.id AND k.status = 'sold'
                 WHERE o.status = 'delivered' GROUP BY o.id HAVING COUNT(k.id) <> 1"),

            'sold key not belonging to a delivered order' => DB::select(
                "SELECT k.id FROM game_keys k LEFT JOIN orders o ON o.id = k.order_id
                 WHERE k.status = 'sold' AND (o.id IS NULL OR o.status <> 'delivered')"),

            'failed/cancelled order still holding a reservation (only key_integrity quarantines)' => DB::select(
                "SELECT o.id FROM orders o JOIN game_keys k ON k.order_id = o.id AND k.status = 'reserved'
                 WHERE o.status IN ('failed','cancelled') AND (o.failure_code IS NULL OR o.failure_code <> 'key_integrity')"),

            'reserved key not belonging to an order' => DB::select(
                "SELECT k.id FROM game_keys k LEFT JOIN orders o ON o.id = k.order_id WHERE k.status = 'reserved' AND o.id IS NULL"),

            // The movement ledger must replay to the key's current state.
            'movement ledger disagrees with key status' => DB::select(
                "SELECT k.id, k.status,
                        SUM(m.type = 'reserved') reserved, SUM(m.type = 'released') released, SUM(m.type = 'sold') sold
                 FROM game_keys k LEFT JOIN inventory_movements m ON m.game_key_id = k.id
                 GROUP BY k.id, k.status
                 HAVING NOT (
                     (k.status = 'available' AND COALESCE(SUM(m.type = 'reserved'), 0) = COALESCE(SUM(m.type = 'released'), 0) AND COALESCE(SUM(m.type = 'sold'), 0) = 0)
                  OR (k.status = 'reserved'  AND SUM(m.type = 'reserved') = SUM(m.type = 'released') + 1 AND SUM(m.type = 'sold') = 0)
                  OR (k.status = 'sold'      AND SUM(m.type = 'reserved') = SUM(m.type = 'released') + 1 AND SUM(m.type = 'sold') = 1)
                 )"),

            'movement referencing an order of another game' => DB::select(
                'SELECT m.id FROM inventory_movements m JOIN orders o ON o.id = m.order_id WHERE o.game_id <> m.game_id'),
        ];

        self::report($violations);
    }

    public static function assertOutboxConsistent(): void
    {
        $violations = [
            'delivered order without exactly one order.delivered message' => DB::select(
                "SELECT o.id FROM orders o LEFT JOIN outbox_messages m ON m.aggregate_type = 'Order' AND m.aggregate_id = o.id AND m.type = 'order.delivered'
                 WHERE o.status = 'delivered' GROUP BY o.id HAVING COUNT(m.id) <> 1"),

            'order.delivered message for an order that is not delivered' => DB::select(
                "SELECT m.id FROM outbox_messages m LEFT JOIN orders o ON o.id = m.aggregate_id
                 WHERE m.type = 'order.delivered' AND (o.id IS NULL OR o.status <> 'delivered')"),
        ];

        self::report($violations);
    }

    /** @param  array<string, list<object>>  $violations */
    private static function report(array $violations): void
    {
        $found = array_filter($violations);

        Assert::assertSame([], $found, 'Invariant violations: '.json_encode($found, JSON_PRETTY_PRINT));
    }
}
