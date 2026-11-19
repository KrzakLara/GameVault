<?php

/*
 * Genuine multi-process concurrency on MySQL 8.4 / InnoDB.
 *
 * Every worker is an independent OS process that boots the full Laravel application and opens
 * its own MySQL connection; checkouts go through the complete HTTP kernel. A file barrier
 * releases all workers at the same instant, and test-only hooks hold the application's own row
 * locks for a while so every transaction is provably in flight at the same time.
 */

use App\Enums\KeyStatus;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\Concurrency\ConcurrentRun;
use Tests\Support\Concurrency\WorkerResult;
use Tests\Support\InteractsWithCommittedData;
use Tests\Support\Invariants;

uses(InteractsWithCommittedData::class);

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql', 'Concurrency tests require MySQL; they are never run against SQLite.');
});

/** @param  list<WorkerResult>  $results */
function statusCounts(array $results): array
{
    $counts = collect($results)->map(fn (WorkerResult $r) => $r->ok ? $r->get('status') : 'crashed')->countBy()->all();
    ksort($counts);

    return $counts;
}

function checkoutTask(User $user, Game $game, ?string $key = null, array $hooks = []): array
{
    return [
        'scenario' => 'http-checkout',
        'args' => ['user_id' => $user->id, 'game_slug' => $game->slug, 'idempotency_key' => $key ?? (string) Str::uuid()],
        'hooks' => $hooks,
    ];
}

/**
 * Block until another session is waiting inside a plain (blocking) FOR UPDATE on game_keys.
 * Fails fast, with the worker's output, if the buyer finished instead of blocking.
 */
function waitForBlockedKeyLock(Process $buyer, float $timeoutSeconds = 30): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $processlist = DB::select('SHOW FULL PROCESSLIST');
        $waiting = collect($processlist)->contains(fn ($row) => is_string($row->Info)
            && str_contains($row->Info, 'from `game_keys`')
            && str_contains($row->Info, 'for update')
            && ! str_contains($row->Info, 'skip locked'));
        if ($waiting) {
            return;
        }
        if (! $buyer->isRunning()) {
            throw new RuntimeException("The buyer finished without ever blocking on the key lock.\nSTDOUT: {$buyer->getOutput()}\nSTDERR: {$buyer->getErrorOutput()}");
        }
        usleep(20_000);
    }

    throw new RuntimeException('The buyer never blocked on the key lock. Processlist: '.json_encode($processlist ?? []));
}

function lockHolder(): Connection
{
    config(['database.connections.lock_holder' => config('database.connections.'.config('database.default'))]);
    DB::purge('lock_holder');

    return DB::connection('lock_holder');
}

it('sells the last key exactly once when 10 buyers race through the HTTP stack, then fulfills it exactly once under competing workers', function () {
    $game = Game::factory()->create();
    $key = GameKey::factory()->for($game)->create();
    $buyers = User::factory()->count(10)->create();

    // Phase 1: 10 simultaneous checkouts. The winner holds its reservation lock for 1.5 s.
    $results = ConcurrentRun::run(
        $buyers->map(fn (User $u) => checkoutTask($u, $game, hooks: ['sleep_after_key_reserved_ms' => 1500]))->all(),
        ['QUEUE_CONNECTION' => 'null'], // fulfillment is exercised separately in phase 2
    );

    expect(ConcurrentRun::allOverlapped($results))->toBeTrue('all checkouts must be in flight simultaneously')
        ->and(count(array_unique(array_map(fn ($r) => $r->get('connection_id'), $results))))->toBe(10)
        ->and(statusCounts($results))->toBe([201 => 1, 409 => 9]);

    $losers = array_values(array_filter($results, fn (WorkerResult $r) => $r->get('status') === 409));
    expect(collect($losers)->pluck('result.error')->unique()->all())->toBe(['out_of_stock']);

    // No false stockout: every loser answered "sold out" only AFTER the winner's reservation
    // had committed, i.e. it waited for the locked key instead of skipping it. (A SKIP LOCKED-only
    // allocator answers while the winner is still holding the lock and fails this check.)
    $winner = collect($results)->first(fn (WorkerResult $r) => $r->get('status') === 201);
    expect($winner->lastCommitAt)->not->toBeNull();
    foreach ($losers as $loser) {
        expect($loser->finishedAt)->toBeGreaterThan($winner->lastCommitAt);
    }

    $order = Order::sole();
    expect($winner->get('order'))->toBe($order->public_id)
        ->and($key->fresh()->status)->toBe(KeyStatus::Reserved)
        ->and($key->fresh()->order_id)->toBe($order->id)
        ->and(InventoryMovement::count())->toBe(1); // only the winner's reservation committed
    Invariants::assertAll();

    // Phase 2: at-least-once delivery means duplicate fulfillment jobs. Six workers run the same
    // order at once while provisioning takes 300 ms.
    $fulfil = ConcurrentRun::run(
        array_fill(0, 6, ['scenario' => 'fulfill', 'args' => ['order_id' => $order->id], 'hooks' => ['provision' => ['sleep_ms' => 300]]]),
        ['QUEUE_CONNECTION' => 'null'],
    );

    $outcomes = collect($fulfil)->map(fn (WorkerResult $r) => $r->ok ? $r->get('outcome') : 'crashed');
    expect($outcomes->filter(fn ($o) => $o === 'delivered')->count())->toBe(1)
        ->and($outcomes->reject(fn ($o) => in_array($o, ['delivered', 'busy', 'already_final'], true))->all())->toBe([]);

    $order->refresh();
    expect($order->status->value)->toBe('delivered')
        ->and($order->attempts)->toBe(1) // exactly one claim: duplicates never provisioned concurrently
        ->and($key->fresh()->status)->toBe(KeyStatus::Sold)
        ->and(DB::table('outbox_messages')->count())->toBe(1)
        ->and(DB::table('game_keys')->where('status', 'reserved')->count())->toBe(0);
    Invariants::assertAll();
});

it('allocates exactly 5 distinct keys to 20 simultaneous buyers and rejects the other 15', function () {
    $game = Game::factory()->create();
    GameKey::factory()->count(5)->for($game)->create();
    $buyers = User::factory()->count(20)->create();

    $results = ConcurrentRun::run(
        $buyers->map(fn (User $u) => checkoutTask($u, $game, hooks: ['sleep_after_key_reserved_ms' => 100]))->all(),
        ['QUEUE_CONNECTION' => 'null'],
    );

    expect(ConcurrentRun::allOverlapped($results))->toBeTrue()
        ->and(statusCounts($results))->toBe([201 => 5, 409 => 15]);

    $orders = Order::query()->with('key')->get();
    expect($orders)->toHaveCount(5)
        ->and($orders->pluck('key.id')->filter()->unique())->toHaveCount(5) // no key shared
        ->and(GameKey::where('status', KeyStatus::Available)->count())->toBe(0)
        ->and(GameKey::where('status', KeyStatus::Reserved)->count())->toBe(5)
        ->and(collect($results)->pluck('result.order')->filter()->sort()->values()->all())
        ->toBe($orders->pluck('public_id')->sort()->values()->all());
    Invariants::assertAll();
});

it('turns 10 simultaneous identical checkouts into one order and nine replays', function () {
    $game = Game::factory()->create();
    GameKey::factory()->count(5)->for($game)->create();
    $user = User::factory()->create();
    $idempotencyKey = (string) Str::uuid();

    // The first INSERT holds the (user_id, idempotency_key) index entry for 200 ms; the other
    // nine INSERTs block on it inside InnoDB, then fail with a duplicate key and replay.
    $results = ConcurrentRun::run(
        array_fill(0, 10, checkoutTask($user, $game, $idempotencyKey, ['sleep_after_order_created_ms' => 200])),
        ['QUEUE_CONNECTION' => 'null'],
    );

    expect(ConcurrentRun::allOverlapped($results))->toBeTrue()
        ->and(statusCounts($results))->toBe([200 => 9, 201 => 1])
        ->and(collect($results)->pluck('result.order')->unique()->all())->toBe([Order::sole()->public_id])
        ->and(collect($results)->pluck('result.replayed')->countBy()->all())->toEqualCanonicalizing(['true' => 9, 'false' => 1])
        ->and(GameKey::where('status', KeyStatus::Reserved)->count())->toBe(1);
    Invariants::assertAll();
});

it('rejects one idempotency key reused concurrently for two different games', function () {
    [$gameA, $gameB] = Game::factory()->count(2)->create();
    GameKey::factory()->count(3)->for($gameA)->create();
    GameKey::factory()->count(3)->for($gameB)->create();
    $user = User::factory()->create();
    $key = (string) Str::uuid();

    $tasks = [];
    foreach ([$gameA, $gameB, $gameA, $gameB, $gameA, $gameB] as $game) {
        $tasks[] = checkoutTask($user, $game, $key, ['sleep_after_order_created_ms' => 200]);
    }
    $results = ConcurrentRun::run($tasks, ['QUEUE_CONNECTION' => 'null']);

    $order = Order::sole();
    $winnerSlug = $order->game->slug;
    $sameGame = collect($results)->filter(fn (WorkerResult $r, int $i) => $tasks[$i]['args']['game_slug'] === $winnerSlug);
    $otherGame = collect($results)->reject(fn (WorkerResult $r, int $i) => $tasks[$i]['args']['game_slug'] === $winnerSlug);

    expect($sameGame->pluck('result.status')->sort()->values()->all())->toBe([200, 200, 201])
        ->and($sameGame->pluck('result.order')->unique()->all())->toBe([$order->public_id])
        ->and($otherGame->pluck('result.status')->unique()->all())->toBe([422])
        ->and($otherGame->pluck('result.error')->unique()->all())->toBe(['idempotency_conflict'])
        ->and(GameKey::where('status', KeyStatus::Reserved)->count())->toBe(1);
    Invariants::assertAll();
});

it('does not report a false stockout while the last key is merely locked by a transaction that rolls back', function () {
    $game = Game::factory()->create();
    $key = GameKey::factory()->for($game)->create();
    $buyer = User::factory()->create();

    // The buyer boots first and parks at the barrier, so start-up time is outside the race.
    $buyerRun = ConcurrentRun::stage([checkoutTask($buyer, $game)], ['QUEUE_CONNECTION' => 'null']);

    // Another session locks the only key, as a checkout that is about to fail would.
    $holder = lockHolder();
    $holder->beginTransaction();
    $holder->select('SELECT id FROM game_keys WHERE id = ? FOR UPDATE', [$key->id]);

    $buyerRun->go();

    // SKIP LOCKED found nothing, but committed data still shows a key: the buyer must WAIT.
    waitForBlockedKeyLock($buyerRun->processes()[0]);

    $holder->rollBack();
    $result = $buyerRun->results()[0];

    expect($result->get('status'))->toBe(201)
        ->and($key->fresh()->status)->toBe(KeyStatus::Reserved)
        ->and($key->fresh()->order_id)->toBe(Order::sole()->id);
    Invariants::assertAll();
});

it('reports a true stockout when the transaction holding the last key commits a reservation', function () {
    $game = Game::factory()->create();
    $key = GameKey::factory()->for($game)->create();
    $buyer = User::factory()->create();
    $other = Order::factory()->for($game)->create(); // the competing order
    $buyerRun = ConcurrentRun::stage([checkoutTask($buyer, $game)], ['QUEUE_CONNECTION' => 'null']);

    $holder = lockHolder();
    $holder->beginTransaction();
    $holder->select('SELECT id FROM game_keys WHERE id = ? FOR UPDATE', [$key->id]);
    $holder->update("UPDATE game_keys SET status = 'reserved', order_id = ?, reserved_at = NOW() WHERE id = ?", [$other->id, $key->id]);
    $holder->insert("INSERT INTO inventory_movements (game_id, game_key_id, order_id, type, created_at) VALUES (?, ?, ?, 'reserved', NOW())", [$game->id, $key->id, $other->id]);

    $buyerRun->go();
    waitForBlockedKeyLock($buyerRun->processes()[0]);

    $holder->commit();
    $result = $buyerRun->results()[0];

    expect($result->get('status'))->toBe(409)
        ->and($result->get('error'))->toBe('out_of_stock')
        ->and(Order::count())->toBe(1) // only the competing order; the rejected checkout left nothing
        ->and($key->fresh()->order_id)->toBe($other->id);
    Invariants::assertAll();
});

it('retries lock-wait timeouts and answers 503 without side effects when the lock is never released, then a retry with the same key succeeds', function () {
    $game = Game::factory()->create();
    $key = GameKey::factory()->for($game)->create();
    $buyer = User::factory()->create();
    $idempotencyKey = (string) Str::uuid();

    $holder = lockHolder();
    $holder->beginTransaction();
    $holder->select('SELECT id FROM game_keys WHERE id = ? FOR UPDATE', [$key->id]);

    // 1 s lock-wait timeout in the worker: every attempt times out (MySQL error 1205) and is
    // retried with backoff until the attempt budget is spent.
    $process = ConcurrentRun::start(checkoutTask($buyer, $game, $idempotencyKey), ['QUEUE_CONNECTION' => 'null', 'DB_LOCK_WAIT_TIMEOUT' => 1]);
    $process->wait();
    $holder->rollBack();
    $result = WorkerResult::fromProcess(0, $process);

    expect($result->get('status'))->toBe(503)
        ->and($result->get('error'))->toBe('temporarily_unavailable')
        ->and($result->transactionsBegun)->toBe((int) config('gamevault.checkout.transaction_attempts'))
        ->and(Order::count())->toBe(0)
        ->and($key->fresh()->status)->toBe(KeyStatus::Available);

    // The client retries the SAME request once the lock is gone: it is accepted normally.
    $retry = ConcurrentRun::run([checkoutTask($buyer, $game, $idempotencyKey)], ['QUEUE_CONNECTION' => 'null']);
    expect($retry[0]->get('status'))->toBe(201)->and(Order::count())->toBe(1);
    Invariants::assertAll();
});

it('absorbs a genuine InnoDB deadlock by retrying the victim transaction', function () {
    [$a, $b] = Game::factory()->count(2)->create();

    $results = ConcurrentRun::run([
        ['scenario' => 'lock-pair', 'args' => ['first' => $a->id, 'second' => $b->id]],
        ['scenario' => 'lock-pair', 'args' => ['first' => $b->id, 'second' => $a->id]],
    ]);

    // Both complete. Exactly one was chosen as the deadlock victim and ran a second time.
    expect(collect($results)->every(fn (WorkerResult $r) => $r->ok))->toBeTrue()
        ->and(collect($results)->pluck('result.attempts')->sort()->values()->all())->toBe([1, 2]);
});
