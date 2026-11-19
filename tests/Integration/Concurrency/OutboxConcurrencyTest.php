<?php

/*
 * Transactional outbox under real multi-process competition on MySQL.
 */

use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\OutboxStatus;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Services\Outbox\Handlers\OrderDeliveredHandler;
use App\Services\Outbox\Outbox;
use App\Services\Outbox\OutboxPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Tests\Support\Concurrency\ConcurrentRun;
use Tests\Support\Concurrency\Hooks;
use Tests\Support\Concurrency\WorkerResult;
use Tests\Support\InteractsWithCommittedData;
use Tests\Support\Invariants;

uses(InteractsWithCommittedData::class);

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql');
    $this->scratch = ConcurrentRun::scratchDirectory('outbox');
});

afterEach(function () {
    File::deleteDirectory($this->scratch);
});

/** Delivered orders with their committed order.delivered outbox messages. */
function deliveredWithMessages(int $count): Collection
{
    $orders = Order::factory()->count($count)->delivered()->create();
    DB::transaction(fn () => $orders->each(fn (Order $o) => app(Outbox::class)
        ->record('order.delivered', "order.delivered:{$o->id}", $o, ['order_id' => $o->id])));

    return $orders;
}

it('lets 6 concurrent publishers deliver 60 messages with every message handled exactly once, even with stale optimizer statistics', function () {
    // Statistics computed on an EMPTY table while a backlog arrives: what happens after a burst in
    // production, until InnoDB's background recalculation catches up. On such statistics MySQL
    // planned the claim as a full scan + filesort, which locks EVERY due row before LIMIT applies:
    // one publisher locked the whole backlog and the other five claimed nothing (seen in a full
    // suite run). Statistics are frozen here so that condition is reproduced deterministically.
    DB::statement('ALTER TABLE outbox_messages STATS_AUTO_RECALC = 0');
    try {
        DB::statement('ANALYZE TABLE outbox_messages');
        deliveredWithMessages(60);
        $log = "{$this->scratch}/handled";

        $results = ConcurrentRun::run(array_fill(0, 6, [
            'scenario' => 'publish-outbox',
            'args' => ['limit' => 5, 'batches' => 4],
            'hooks' => ['outbox_handler' => ['record_to' => $log, 'sleep_ms' => 20]],
        ]));
    } finally {
        DB::statement('ALTER TABLE outbox_messages STATS_AUTO_RECALC = DEFAULT');
    }

    $handled = collect(Hooks::read($log));
    $diagnostics = implode("\n", array_map(fn (WorkerResult $r) => $r->describe(), $results));
    expect(collect($results)->every(fn (WorkerResult $r) => $r->ok))->toBeTrue($diagnostics)
        ->and(collect($results)->sum('result.published'))->toBe(60, $diagnostics)
        ->and($handled)->toHaveCount(60)
        ->and($handled->pluck('event_id')->unique())->toHaveCount(60) // no message handled twice
        ->and($handled->pluck('pid')->unique()->count())->toBeGreaterThan(1) // the work was really shared
        ->and(OutboxMessage::where('status', OutboxStatus::Published)->count())->toBe(60)
        ->and(OutboxMessage::where('attempts', '<>', 1)->count())->toBe(0);
    Invariants::assertAll();
});

it('redelivers a message whose publisher was killed after the side effect but before marking it published (at-least-once), with no duplicate business effect', function () {
    [$order] = deliveredWithMessages(1);
    $log = "{$this->scratch}/handled";

    $crasher = ConcurrentRun::start(
        ['scenario' => 'publish-outbox', 'hooks' => ['outbox_handler' => ['record_to' => $log, 'crash_after_handle' => true]]],
        ['GAMEVAULT_OUTBOX_LEASE_SECONDS' => 30],
    );
    try {
        $crasher->wait();
    } catch (ProcessSignaledException) {
        // Expected: the worker SIGKILLed itself inside the handler.
    }
    expect($crasher->getTermSignal())->toBe(SIGKILL);

    // The side effect happened (the e-mail went out) but the message is still claimed.
    $message = OutboxMessage::sole();
    expect($message->status)->toBe(OutboxStatus::Processing)
        ->and($message->attempts)->toBe(1)
        ->and(Hooks::read($log))->toHaveCount(1);

    // While the dead publisher's lease is live, nobody else claims the message.
    Hooks::install(['outbox_handler' => ['record_to' => $log]]);
    $publisher = app(OutboxPublisher::class);
    expect($publisher->publishBatch()['published'])->toBe(0);

    // After the lease, another publisher reclaims it: the message is never lost.
    $this->travel(31)->seconds();
    expect($publisher->publishBatch()['published'])->toBe(1);

    $message->refresh();
    $handled = collect(Hooks::read($log));
    expect($message->status)->toBe(OutboxStatus::Published)
        ->and($message->attempts)->toBe(2)
        // At-least-once: the external side effect ran twice for the same event. Exactly-once
        // delivery to an external system needs the receiver to deduplicate on event_id.
        ->and($handled)->toHaveCount(2)
        ->and($handled->pluck('event_id')->unique()->all())->toBe(["order.delivered:{$order->id}"])
        // Business state is untouched by redelivery: one delivery, one sold key, one message.
        ->and($order->fresh()->status->value)->toBe('delivered')
        ->and(OutboxMessage::count())->toBe(1);
    Invariants::assertAll();
});

it('fences a publisher whose lease expired: its late completion cannot overwrite the new owner', function () {
    deliveredWithMessages(1);
    $log = "{$this->scratch}/handled";

    // The second publisher boots first and parks at the barrier: its start-up time can never
    // eat into the window in which it must take over.
    $fastRun = ConcurrentRun::stage([['scenario' => 'publish-outbox', 'hooks' => ['outbox_handler' => ['record_to' => $log]]]]);

    $slow = ConcurrentRun::start(
        ['scenario' => 'publish-outbox', 'hooks' => ['outbox_handler' => ['record_to' => $log, 'sleep_ms' => 6000]]],
        ['GAMEVAULT_OUTBOX_LEASE_SECONDS' => 1],
    );

    $deadline = microtime(true) + 60;
    while (OutboxMessage::sole()->status !== OutboxStatus::Processing) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    usleep(1_300_000); // the slow publisher's 1 s lease has expired (real clock)

    $fast = $fastRun->go()->results();
    expect($fast[0]->get('published'))->toBe(1, $fast[0]->describe());

    $slow->wait();
    $late = WorkerResult::fromProcess(0, $slow);
    expect($late->get('stale'))->toBe(1)
        ->and($late->get('published'))->toBe(0);

    $message = OutboxMessage::sole();
    expect($message->status)->toBe(OutboxStatus::Published)
        ->and($message->attempts)->toBe(2)
        ->and($message->lock_token)->toBeNull()
        ->and(Hooks::read($log))->toHaveCount(2);
});

it('publishes only committed state: the handler observes the delivery from an independent connection', function () {
    $order = Order::factory()->withReservedKey()->create();
    config(['database.connections.observer' => config('database.connections.'.config('database.default'))]);
    $observed = new ArrayObject;

    app()->instance(OrderDeliveredHandler::class, new class($observed)
    {
        public function __construct(private ArrayObject $observed) {}

        public function handle(OutboxMessage $message): void
        {
            // A different session sees only committed data.
            $this->observed[] = DB::connection('observer')->table('orders')->where('id', $message->payload['order_id'])->value('status');
        }
    });

    // Sync queue: the publisher job runs right after the fulfillment transaction commits.
    app(FulfillOrder::class)->handle($order->id);

    expect($observed->getArrayCopy())->toBe(['delivered'])
        ->and(OutboxMessage::sole()->status)->toBe(OutboxStatus::Published);
    DB::purge('observer');
});
