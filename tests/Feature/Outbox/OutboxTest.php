<?php

use App\Enums\OutboxStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Notifications\OrderDeliveredNotification;
use App\Services\Outbox\Handlers\OrderDeliveredHandler;
use App\Services\Outbox\Outbox;
use App\Services\Outbox\OutboxPublisher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

function recordDelivered(Order $order): void
{
    DB::transaction(fn () => app(Outbox::class)->record('order.delivered', "order.delivered:{$order->id}", $order, ['order_id' => $order->id]));
}

/** Handler double that records calls and can throw. */
function outboxHandler(?Throwable $throw = null): object
{
    $handler = new class($throw)
    {
        public int $calls = 0;

        public function __construct(private ?Throwable $throw) {}

        public function handle(OutboxMessage $message): void
        {
            $this->calls++;
            if ($this->throw) {
                throw $this->throw;
            }
        }
    };
    app()->instance(OrderDeliveredHandler::class, $handler);

    return $handler;
}

function publisher(): OutboxPublisher
{
    return app(OutboxPublisher::class);
}

beforeEach(function () {
    $this->order = Order::factory()->delivered()->create();
    $this->freezeSecond(); // timestamps are stored with second precision
});

it('commits atomically with the business change: a rollback leaves no message', function () {
    try {
        DB::transaction(function () {
            $this->order->recordEvent('something_happened');
            app(Outbox::class)->record('order.delivered', "order.delivered:{$this->order->id}", $this->order, ['order_id' => $this->order->id]);
            throw new RuntimeException('business transaction fails');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::count())->toBe(0)->and($this->order->events()->count())->toBe(0);
});

it('ignores a duplicate business event without breaking the surrounding transaction', function () {
    DB::transaction(function () {
        expect(app(Outbox::class)->record('order.delivered', 'evt-1', $this->order, ['order_id' => $this->order->id]))->toBeTrue()
            ->and(app(Outbox::class)->record('order.delivered', 'evt-1', $this->order, ['order_id' => $this->order->id]))->toBeFalse();
        $this->order->recordEvent('after_duplicate'); // the transaction is still usable
    });

    expect(OutboxMessage::count())->toBe(1)->and($this->order->events()->where('type', 'after_duplicate')->count())->toBe(1);
});

it('accepts identifiers only in payloads', function (array $payload) {
    expect(fn () => DB::transaction(fn () => app(Outbox::class)->record('order.delivered', 'evt-x', $this->order, $payload)))
        ->toThrow(InvalidArgumentException::class);
    expect(OutboxMessage::count())->toBe(0);
})->with([
    'key code' => [['code' => 'DEMO-ABCDE-12345']],
    'e-mail address' => [['email' => 'user@example.com']],
    'nested data' => [['order_id' => ['id' => 1]]],
    'oversized value' => [['order_id' => str_repeat('9', 65)]],
    'list payload' => [['DEMO-ABCDE-12345']],
]);

it('publishes a due message exactly once and records the outcome', function () {
    $handler = outboxHandler();
    recordDelivered($this->order);

    expect(publisher()->publishBatch())->toBe(['published' => 1, 'retried' => 0, 'dead_lettered' => 0, 'stale' => 0])
        ->and(publisher()->publishBatch()['published'])->toBe(0)
        ->and($handler->calls)->toBe(1);

    $message = OutboxMessage::sole();
    expect($message->status)->toBe(OutboxStatus::Published)
        ->and($message->published_at)->not->toBeNull()
        ->and($message->lock_token)->toBeNull()
        ->and($message->attempts)->toBe(1);
});

it('retries with exponential backoff, then dead-letters with a redacted error after max attempts', function () {
    outboxHandler(new RuntimeException('550 mailbox unavailable for jane.doe@example.com'));
    recordDelivered($this->order);

    foreach ([30, 60, 120, 240, 480, 960, 1920] as $attempt => $delay) {
        expect(publisher()->publishBatch()['retried'])->toBe(1);
        $message = OutboxMessage::sole();
        expect($message->status)->toBe(OutboxStatus::Pending)
            ->and($message->attempts)->toBe($attempt + 1)
            ->and((int) now()->diffInSeconds($message->available_at))->toBe($delay)
            ->and(publisher()->publishBatch()['retried'])->toBe(0); // not due before its backoff
        $this->travel($delay)->seconds();
    }

    expect(publisher()->publishBatch()['dead_lettered'])->toBe(1);
    $message = OutboxMessage::sole();
    expect($message->status)->toBe(OutboxStatus::DeadLetter)
        ->and($message->attempts)->toBe(8)
        ->and($message->dead_lettered_at)->not->toBeNull()
        ->and($message->last_error)->toContain('[redacted-email]')->not->toContain('jane.doe@example.com');

    $this->travel(1)->days();
    expect(publisher()->publishBatch())->toBe(['published' => 0, 'retried' => 0, 'dead_lettered' => 0, 'stale' => 0]);
});

it('caps the backoff at one hour', function () {
    expect(OutboxPublisher::backoffSeconds(1))->toBe(30)
        ->and(OutboxPublisher::backoffSeconds(7))->toBe(1920)
        ->and(OutboxPublisher::backoffSeconds(8))->toBe(3600)
        ->and(OutboxPublisher::backoffSeconds(50))->toBe(3600);
});

it('reclaims a message whose publisher died, and fences the dead publisher out', function () {
    $handler = outboxHandler();
    recordDelivered($this->order);

    $stale = publisher()->claim(10)->sole(); // publisher A claims, then "dies"
    expect(publisher()->claim(10))->toHaveCount(0); // lease still live: nobody else may take it

    $this->travel(config('gamevault.outbox.lease_seconds') + 1)->seconds();
    $current = publisher()->claim(10)->sole(); // publisher B reclaims it

    expect($current->lock_token)->not->toBe($stale->lock_token)
        ->and($current->attempts)->toBe(2)
        ->and(publisher()->deliver($stale))->toBe('stale') // A's late completion changes nothing
        ->and(OutboxMessage::sole()->status)->toBe(OutboxStatus::Processing)
        ->and(publisher()->deliver($current))->toBe('published')
        ->and($handler->calls)->toBe(2); // at-least-once: the side effect ran for both
});

it('never claims messages that are not due, already published or dead-lettered', function () {
    outboxHandler();
    foreach ([['pending', now()->addMinute()], ['published', now()], ['dead_letter', now()]] as $i => [$status, $at]) {
        OutboxMessage::forceCreate(['event_id' => "evt-{$i}", 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $this->order->id,
            'payload' => ['order_id' => $this->order->id], 'status' => $status, 'available_at' => $at, 'published_at' => $status === 'published' ? now() : null]);
    }

    expect(publisher()->claim(10))->toHaveCount(0);
});

it('requeues dead letters with a fresh attempt budget and an audit entry', function () {
    $admin = User::factory()->admin()->create();
    OutboxMessage::forceCreate(['event_id' => 'evt-dead', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $this->order->id,
        'payload' => ['order_id' => $this->order->id], 'status' => 'dead_letter', 'attempts' => 8, 'available_at' => now(), 'dead_lettered_at' => now()]);

    expect(publisher()->requeueDeadLetters($admin))->toBe(1);

    expect(OutboxMessage::sole()->only(['status', 'attempts', 'dead_lettered_at']))->toEqual(['status' => OutboxStatus::Pending, 'attempts' => 0, 'dead_lettered_at' => null])
        ->and(AuditLog::where('action', 'outbox.requeue')->sole()->actor_id)->toBe($admin->id);
});

it('exposes observable stats: counts per status, oldest due message, expired leases', function () {
    recordDelivered($this->order);
    $this->travel(90)->seconds();
    OutboxMessage::forceCreate(['event_id' => 'evt-stuck', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $this->order->id,
        'payload' => ['order_id' => $this->order->id], 'status' => 'processing', 'attempts' => 1, 'lock_token' => '01JAAAAAAAAAAAAAAAAAAAAAAA',
        'locked_until' => now()->subSecond(), 'available_at' => now()->subSecond()]);

    expect(publisher()->stats())->toBe([
        'counts' => ['pending' => 1, 'processing' => 1, 'published' => 0, 'dead_letter' => 0],
        'oldest_due_seconds' => 90,
        'expired_leases' => 1,
    ]);

    $this->artisan('outbox:status --json')->expectsOutputToContain('"expired_leases":1')->assertSuccessful();
});

it('logs every state change with identifiers only, never the payload', function () {
    $logs = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => $logs->push([$e->message, $e->context]));
    outboxHandler(new RuntimeException('temporary'));
    recordDelivered($this->order);

    publisher()->publishBatch();

    expect($logs->pluck(0)->all())->toContain('outbox.recorded', 'outbox.claimed', 'outbox.retry_scheduled')
        ->and($logs->pluck(1)->flatMap(fn (array $c) => array_keys($c))->unique()->all())->not->toContain('payload');
});

it('sends a delivery e-mail that links to the order and never contains the key', function () {
    $order = Order::factory()->delivered('MAIL-SECRET-99999')->create();

    $html = (string) (new OrderDeliveredNotification($order))->toMail($order->user)->render();

    expect($html)->toContain(route('orders.show', $order))
        ->and($html)->toContain($order->public_id)
        ->and($html)->not->toContain('MAIL-SECRET-99999');
});

it('publishes the real handler end to end, and skips the e-mail (without retrying) for a deleted account', function () {
    Notification::fake();
    recordDelivered($this->order);
    $other = Order::factory()->delivered()->create();
    recordDelivered($other);
    $other->user->delete(); // the order is kept, anonymised (user_id -> NULL)

    expect(publisher()->publishBatch()['published'])->toBe(2);

    Notification::assertSentTo($this->order->user, OrderDeliveredNotification::class);
    Notification::assertCount(1);
    expect($other->fresh()->user_id)->toBeNull();
});

it('runs from the console', function () {
    outboxHandler();
    recordDelivered($this->order);

    $this->artisan('outbox:publish')->expectsOutputToContain('published=1')->assertSuccessful();
});
