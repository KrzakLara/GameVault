<?php

/*
 * Fulfillment under real multi-process competition on MySQL: competing workers, duplicate queue
 * deliveries processed by real `artisan queue:work` processes, a worker killed with SIGKILL in
 * the middle of provisioning, and a slow worker whose lease is taken over.
 */

use App\Actions\Fulfillment\FulfillmentOutcome;
use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\FailureCode;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concurrency\ConcurrentRun;
use Tests\Support\Concurrency\Hooks;
use Tests\Support\Concurrency\WorkerResult;
use Tests\Support\InteractsWithCommittedData;
use Tests\Support\Invariants;

uses(InteractsWithCommittedData::class);

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql');
    $this->scratch = ConcurrentRun::scratchDirectory('fulfillment');
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

function waitForFile(string $path, float $timeoutSeconds = 30): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Timed out waiting for {$path}");
        }
        usleep(20_000);
    }
}

it('delivers the only key to exactly one of 10 competing unreserved orders and fails the rest as out of stock', function () {
    // Orders without a reservation exist for real: rows created before reservation-at-checkout
    // (migration 000002 backfilled them as pending without a key).
    $game = Game::factory()->create();
    $key = GameKey::factory()->for($game)->create();
    $orders = Order::factory()->count(10)->for($game)->create();

    $results = ConcurrentRun::run(
        $orders->map(fn (Order $o) => ['scenario' => 'fulfill', 'args' => ['order_id' => $o->id], 'hooks' => ['sleep_after_key_reserved_ms' => 200]])->all(),
        ['QUEUE_CONNECTION' => 'null'],
    );

    expect(ConcurrentRun::allOverlapped($results))->toBeTrue()
        ->and(collect($results)->pluck('result.outcome')->countBy()->sortKeys()->all())->toBe(['delivered' => 1, 'failed' => 9]);

    $delivered = Order::where('status', OrderStatus::Delivered)->sole();
    expect($key->fresh()->status)->toBe(KeyStatus::Sold)
        ->and($key->fresh()->order_id)->toBe($delivered->id)
        ->and(Order::where('status', OrderStatus::Failed)->where('failure_code', FailureCode::OutOfStock)->count())->toBe(9)
        ->and(GameKey::where('status', KeyStatus::Reserved)->count())->toBe(0);
    Invariants::assertAll();
});

it('fulfills 20 competing unreserved orders against 5 keys: 5 delivered, 15 out of stock, no key twice', function () {
    $game = Game::factory()->create();
    GameKey::factory()->count(5)->for($game)->create();
    $orders = Order::factory()->count(20)->for($game)->create();

    $results = ConcurrentRun::run(
        $orders->map(fn (Order $o) => ['scenario' => 'fulfill', 'args' => ['order_id' => $o->id], 'hooks' => ['sleep_after_key_reserved_ms' => 100]])->all(),
        ['QUEUE_CONNECTION' => 'null'],
    );

    expect(collect($results)->pluck('result.outcome')->countBy()->sortKeys()->all())->toBe(['delivered' => 5, 'failed' => 15])
        ->and(GameKey::where('status', KeyStatus::Sold)->distinct()->count('order_id'))->toBe(5)
        ->and(GameKey::where('status', '!=', KeyStatus::Sold)->count())->toBe(0);
    Invariants::assertAll();
});

it('processes 8 duplicate queue deliveries of one order on 4 real queue workers with exactly one delivery', function () {
    $order = Order::factory()->withReservedKey()->create();

    config(['queue.default' => 'database']);
    foreach (range(1, 8) as $_) {
        // pushOn(): a raw push() ignores the job's onQueue(); only the Bus dispatcher honours it.
        Queue::connection('database')->pushOn('fulfillment', new DeliverGameKey($order->id));
    }
    expect(DB::table('jobs')->where('queue', 'fulfillment')->count())->toBe(8);

    // Real `artisan queue:work` processes; provisioning takes 400 ms so deliveries overlap.
    $workers = ConcurrentRun::artisan(4, ['queue:work', 'database', '--queue=fulfillment', '--stop-when-empty', '--sleep=0'], [
        'QUEUE_CONNECTION' => 'database',
        'GAMEVAULT_FULFILLMENT_LATENCY_MS' => 400,
    ]);
    foreach ($workers as $worker) {
        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
    }

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->attempts)->toBe(1) // one claim; the other seven executions were no-ops
        ->and(DB::table('jobs')->where('queue', 'fulfillment')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(OrderEvent::where('order_id', $order->id)->where('to_status', 'delivered')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->count())->toBe(1);
    Invariants::assertAll();
});

it('keeps the reservation when a worker is SIGKILLed mid-provisioning, refuses a second concurrent owner, and recovers after the lease expires', function () {
    $order = Order::factory()->withReservedKey()->create();
    $key = $order->key;
    $started = "{$this->scratch}/provisioning";

    $victim = ConcurrentRun::start(
        ['scenario' => 'fulfill', 'args' => ['order_id' => $order->id], 'hooks' => ['provision' => ['touch_when_started' => $started, 'sleep_ms' => 60_000]]],
        ['QUEUE_CONNECTION' => 'null', 'GAMEVAULT_FULFILLMENT_LEASE_SECONDS' => 5],
    );
    waitForFile($started);

    $victim->signal(SIGKILL); // hard crash: no exception handler, no relinquish, no rollback hook
    $victim->wait();
    expect($victim->getTermSignal())->toBe(SIGKILL);

    // The crash happened after the claim committed: the order is still owned by the dead worker,
    // and its key is still reserved (never released, never sold).
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Processing)
        ->and($order->hasLiveLease())->toBeTrue()
        ->and($key->fresh()->status)->toBe(KeyStatus::Reserved)
        ->and(DB::table('outbox_messages')->count())->toBe(0);

    // A redelivered job while the lease is live must not start a second, concurrent provisioning.
    expect(app(FulfillOrder::class)->handle($order->id))->toBe(FulfillmentOutcome::Busy);

    // Once the lease has run out, the recovery sweep takes over and completes exactly once.
    $this->travel(6)->seconds();
    Artisan::call('gamevault:recover-stuck');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->attempts)->toBe(2)
        ->and(OrderEvent::where('order_id', $order->id)->where('type', 'fulfillment_lease_taken_over')->count())->toBe(1)
        ->and($key->fresh()->status)->toBe(KeyStatus::Sold)
        ->and(DB::table('outbox_messages')->count())->toBe(1);
    Invariants::assertAll();
});

it('fences a slow worker whose lease was taken over: it cannot finalize the order afterwards', function () {
    $order = Order::factory()->withReservedKey()->create();
    $started = "{$this->scratch}/slow-started";

    // Worker B boots first and parks at the barrier, so its start-up time stays out of the race.
    $fastRun = ConcurrentRun::stage([['scenario' => 'fulfill', 'args' => ['order_id' => $order->id]]], ['QUEUE_CONNECTION' => 'null']);

    // Worker A: 1 s lease, but provisioning takes 8 s (e.g. a hung supplier call).
    $slow = ConcurrentRun::start(
        ['scenario' => 'fulfill', 'args' => ['order_id' => $order->id], 'hooks' => ['provision' => ['touch_when_started' => $started, 'sleep_ms' => 8000]]],
        ['QUEUE_CONNECTION' => 'null', 'GAMEVAULT_FULFILLMENT_LEASE_SECONDS' => 1],
    );
    waitForFile($started, 60);
    usleep(1_300_000); // A's lease has now expired (real clock: B is another process)

    // Worker B takes over with a new fencing token and finishes first.
    $fast = $fastRun->go()->results();
    expect($fast[0]->get('outcome'))->toBe('delivered', $fast[0]->describe());

    $slow->wait();
    $slowResult = WorkerResult::fromProcess(0, $slow);
    expect($slowResult->get('outcome'))->toBe('superseded');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->attempts)->toBe(2)
        ->and(OrderEvent::where('order_id', $order->id)->where('to_status', 'delivered')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->count())->toBe(1);
    Invariants::assertAll();
});

it('never runs external provisioning inside a database transaction', function () {
    $orders = Order::factory()->count(3)->withReservedKey()->create();
    $log = "{$this->scratch}/provision.log";

    $results = ConcurrentRun::run(
        $orders->map(fn (Order $o) => ['scenario' => 'fulfill', 'args' => ['order_id' => $o->id], 'hooks' => ['provision' => ['record_to' => $log]]])->all(),
        ['QUEUE_CONNECTION' => 'null'],
    );

    $calls = Hooks::read($log);
    expect(collect($results)->pluck('result.outcome')->unique()->all())->toBe(['delivered'], implode("\n", array_map(fn ($r) => $r->describe(), $results)))
        ->and($calls)->toHaveCount(3)
        ->and(array_unique(array_column($calls, 'transaction_level')))->toBe([0]);
    Invariants::assertAll();
});
