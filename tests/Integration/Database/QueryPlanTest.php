<?php

/*
 * Query-plan guards for the SKIP LOCKED queries.
 *
 * "FOR UPDATE SKIP LOCKED ... LIMIT n" only spreads work across concurrent claimers if InnoDB
 * walks an index in the ORDER BY order and stops after n rows. With a full scan + filesort it
 * must read, and therefore LOCK, every matching row before LIMIT applies: one claimer then holds
 * the whole backlog and the others skip everything. A full-suite run showed exactly that for the
 * outbox (5 of 6 publishers claimed nothing) when optimizer statistics were stale, which in
 * production happens after a burst, until InnoDB's background recalculation catches up.
 *
 * These tests capture the real SQL the application issues and EXPLAIN it under statistics
 * computed on an EMPTY table (and frozen), the worst case for the optimizer.
 */

use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Services\Inventory\InventoryAllocator;
use App\Services\Outbox\OutboxPublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCommittedData;

uses(InteractsWithCommittedData::class);

/**
 * Freeze the table's optimizer statistics as computed on an empty table while $test runs.
 */
function withEmptyTableStatistics(string $table, Closure $test): void
{
    DB::statement("ALTER TABLE {$table} STATS_AUTO_RECALC = 0");
    try {
        DB::statement("ANALYZE TABLE {$table}");
        $test();
    } finally {
        DB::statement("ALTER TABLE {$table} STATS_AUTO_RECALC = DEFAULT");
    }
}

/**
 * EXPLAIN every locking read executed while $run runs.
 *
 * @return list<object>
 */
function explainLockingReads(Closure $run): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'for update')) {
            $statements[] = $query->toRawSql();
        }
    });

    $run();

    return array_merge(...array_map(fn (string $sql) => array_map(
        fn (object $row) => (object) ['sql' => $sql, 'type' => $row->type, 'key' => $row->key, 'extra' => (string) $row->Extra],
        DB::select('EXPLAIN '.$sql),
    ), $statements));
}

it('claims outbox messages by walking (status, available_at) in order, never with a filesort', function () {
    withEmptyTableStatistics('outbox_messages', function () {
        $orders = Order::factory()->count(60)->delivered()->create();
        foreach ($orders as $order) {
            DB::table('outbox_messages')->insert(['event_id' => "order.delivered:{$order->id}", 'type' => 'order.delivered', 'aggregate_type' => 'Order',
                'aggregate_id' => $order->id, 'payload' => json_encode(['order_id' => $order->id]), 'status' => 'pending', 'attempts' => 0,
                'available_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $plans = explainLockingReads(fn () => app(OutboxPublisher::class)->claim(5));

        expect($plans)->not->toBeEmpty();
        foreach ($plans as $plan) {
            expect($plan->key)->toBe('outbox_messages_status_available_at_index', $plan->sql)
                ->and($plan->extra)->not->toContain('filesort');
        }
    });
});

it('reserves keys by walking (game_id, status, id) in order, never with a filesort', function () {
    withEmptyTableStatistics('game_keys', function () {
        $game = Game::factory()->create();
        GameKey::factory()->count(60)->for($game)->create();
        $orders = Order::factory()->count(2)->for($game)->create();

        // Fast path (SKIP LOCKED) and, with every key locked elsewhere, the blocking fallback.
        $plans = explainLockingReads(fn () => DB::transaction(fn () => app(InventoryAllocator::class)->reserve($orders[0])));

        expect($plans)->not->toBeEmpty();
        foreach ($plans as $plan) {
            if (str_contains($plan->sql, '`order_id` =')) {
                continue; // the "does this order already hold a key" lookup: a unique-index point read
            }
            expect($plan->key)->toBe('game_keys_game_id_status_id_index', $plan->sql)
                ->and($plan->extra)->not->toContain('filesort');
        }
    });
});
