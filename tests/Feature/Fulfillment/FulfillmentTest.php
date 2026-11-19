<?php

use App\Actions\Fulfillment\FailOrder;
use App\Actions\Fulfillment\FulfillmentClaim;
use App\Actions\Fulfillment\FulfillmentOutcome;
use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\FailureCode;
use App\Enums\InventoryMovementType;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\DeliverGameKey;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OutboxMessage;
use App\Notifications\OrderDeliveredNotification;
use App\Services\Fulfillment\KeyProvisioner;
use App\Services\Inventory\InventoryAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Invariants;

/** Provisioner double: counts calls and can fail a given number of times. */
function provisioner(int $failures = 0): KeyProvisioner
{
    $fake = new class($failures) implements KeyProvisioner
    {
        public int $calls = 0;

        public function __construct(private int $failures) {}

        public function provision(FulfillmentClaim $claim): void
        {
            $this->calls++;
            if ($this->failures-- > 0) {
                throw new RuntimeException('Simulated supplier outage');
            }
        }
    };
    app()->instance(KeyProvisioner::class, $fake);

    return $fake;
}

function fulfill(): FulfillOrder
{
    return app(FulfillOrder::class);
}

beforeEach(function () {
    Notification::fake();
});

it('claims, provisions and finalizes: delivered, key sold, timeline, one outbox message, notification after commit', function () {
    $spy = provisioner();
    $order = Order::factory()->withReservedKey()->create();

    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::Delivered);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->lease_token)->toBeNull()
        ->and($order->attempts)->toBe(1)
        ->and($order->key->status)->toBe(KeyStatus::Sold)
        ->and($spy->calls)->toBe(1)
        ->and(OrderEvent::where('order_id', $order->id)->where('type', 'status_changed')->pluck('to_status')->all())->toBe(['processing', 'delivered'])
        ->and(OutboxMessage::sole()->event_id)->toBe("order.delivered:{$order->id}");
    Notification::assertSentTo($order->user, OrderDeliveredNotification::class);
    Invariants::assertAll();
});

it('is a no-op when the same job runs again after delivery (duplicate queue delivery)', function () {
    $spy = provisioner();
    $order = Order::factory()->withReservedKey()->create();
    fulfill()->handle($order->id);

    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::AlreadyFinal)
        ->and($spy->calls)->toBe(1)
        ->and(InventoryMovement::where('type', InventoryMovementType::Sold)->count())->toBe(1)
        ->and(OutboxMessage::count())->toBe(1);
    Invariants::assertAll();
});

it('does nothing while another worker holds a live lease', function () {
    $spy = provisioner();
    $order = Order::factory()->processing(now()->addSeconds(30))->withReservedKey()->create();
    $token = $order->lease_token;

    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::Busy)
        ->and($spy->calls)->toBe(0)
        ->and($order->fresh()->lease_token)->toBe($token)
        ->and($order->fresh()->attempts)->toBe(1);
});

it('takes over an expired lease with a new fencing token and completes', function () {
    $order = Order::factory()->processing(now()->subSecond())->withReservedKey()->create();
    $oldToken = $order->lease_token;
    provisioner();

    $claim = fulfill()->claim($order->id);

    expect($claim)->toBeInstanceOf(FulfillmentClaim::class)
        ->and($claim->takeover)->toBeTrue()
        ->and($claim->token)->not->toBe($oldToken)
        ->and($claim->attempt)->toBe(2)
        ->and(fulfill()->finalize($claim))->toBe(FulfillmentOutcome::Delivered)
        ->and(OrderEvent::where('order_id', $order->id)->where('type', 'fulfillment_lease_taken_over')->count())->toBe(1);
    Invariants::assertAll();
});

it('fences a stale worker: once its lease was taken over it can neither finalize nor fail the order', function () {
    $order = Order::factory()->withReservedKey()->create();

    $stale = fulfill()->claim($order->id);                       // worker A
    $this->travel(config('gamevault.fulfillment.lease_seconds') + 1)->seconds(); // A hangs past its lease
    $current = fulfill()->claim($order->id);                     // worker B takes over

    expect(fulfill()->finalize($stale))->toBe(FulfillmentOutcome::Superseded)
        ->and(app(FailOrder::class)->handle($order->id, FailureCode::KeyIntegrity, leaseToken: $stale->token))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing) // still owned by B, untouched
        ->and($order->key->fresh()->status)->toBe(KeyStatus::Reserved)
        ->and(fulfill()->finalize($current))->toBe(FulfillmentOutcome::Delivered)
        ->and(fulfill()->finalize($stale))->toBe(FulfillmentOutcome::Superseded) // and still nothing after B finished
        ->and(InventoryMovement::where('type', InventoryMovementType::Sold)->count())->toBe(1)
        ->and(OutboxMessage::count())->toBe(1);
    Invariants::assertAll();
});

it('lets a worker whose lease expired finish when nobody took over (its token is still current)', function () {
    $order = Order::factory()->withReservedKey()->create();
    $claim = fulfill()->claim($order->id);
    $this->travel(config('gamevault.fulfillment.lease_seconds') + 5)->seconds();

    expect(fulfill()->finalize($claim))->toBe(FulfillmentOutcome::Delivered);
});

it('relinquishes its lease on a transient provisioning error so the retry can take over at once', function () {
    $spy = provisioner(failures: 1);
    $order = Order::factory()->withReservedKey()->create();

    expect(fn () => fulfill()->handle($order->id))->toThrow(RuntimeException::class, 'Simulated supplier outage');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Processing)
        ->and($order->hasLiveLease())->toBeFalse() // relinquished, not left to expire
        ->and($order->key->status)->toBe(KeyStatus::Reserved);

    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::Delivered)
        ->and($spy->calls)->toBe(2)
        ->and($order->fresh()->attempts)->toBe(2);
    Invariants::assertAll();
});

it('rolls back the whole finalize step when anything in it fails, then succeeds on retry', function () {
    provisioner();
    $order = Order::factory()->withReservedKey()->create();
    OrderEvent::creating(function (OrderEvent $event) {
        if ($event->to_status === 'delivered') {
            throw new RuntimeException('crash between "key sold" and "order delivered"');
        }
    });

    expect(fn () => fulfill()->handle($order->id))->toThrow(RuntimeException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->key->fresh()->status)->toBe(KeyStatus::Reserved) // markSold was rolled back too
        ->and(InventoryMovement::where('type', InventoryMovementType::Sold)->count())->toBe(0)
        ->and(OutboxMessage::count())->toBe(0);
    Notification::assertNothingSent();

    OrderEvent::flushEventListeners();
    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::Delivered);
    Invariants::assertAll();
});

it('fails with key_integrity and quarantines a key whose ciphertext is broken (never resold)', function () {
    provisioner();
    $order = Order::factory()->withReservedKey()->create();
    DB::table('game_keys')->where('id', $order->key->id)->update(['code' => 'not-a-valid-ciphertext']);

    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::Failed);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Failed)
        ->and($order->failure_code)->toBe(FailureCode::KeyIntegrity)
        ->and($order->failure_code->isRetryable())->toBeFalse()
        ->and($order->key->status)->toBe(KeyStatus::Reserved) // quarantined against the failed order
        ->and(GameKey::where('status', KeyStatus::Available)->count())->toBe(0);
    Invariants::assertAll();
});

it('fails with reservation_missing when the reservation vanished between claim and finalize', function () {
    $order = Order::factory()->withReservedKey()->create();
    $claim = fulfill()->claim($order->id);
    DB::transaction(fn () => app(InventoryAllocator::class)->release($order->key->fresh(), $order->fresh()));

    expect(fulfill()->finalize($claim))->toBe(FulfillmentOutcome::Failed)
        ->and($order->fresh()->failure_code)->toBe(FailureCode::ReservationMissing);
    Invariants::assertAll();
});

it('fails with attempts_exhausted at the per-cycle cap and returns the key to stock', function () {
    config(['gamevault.fulfillment.max_attempts' => 3]);
    $order = Order::factory()->processing(now()->subSecond())->withReservedKey()->create(['attempts' => 3]);

    expect(fulfill()->handle($order->id))->toBe(FulfillmentOutcome::Failed);

    expect($order->fresh()->failure_code)->toBe(FailureCode::AttemptsExhausted)
        ->and($order->key()->exists())->toBeFalse()
        ->and(GameKey::sole()->status)->toBe(KeyStatus::Available)
        ->and(InventoryMovement::where('type', InventoryMovementType::Released)->count())->toBe(1);
    Invariants::assertAll();
});

it('fails the order and releases its key when the queue gives up and no live worker owns it', function () {
    $order = Order::factory()->withReservedKey()->create();

    (new DeliverGameKey($order->id))->failed(new RuntimeException('boom'));

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->failure_code)->toBe(FailureCode::AttemptsExhausted)
        ->and(GameKey::sole()->status)->toBe(KeyStatus::Available);
    Invariants::assertAll();
});

it('defers the queue-level failure while a live worker still owns the order', function () {
    $order = Order::factory()->processing(now()->addSeconds(30))->withReservedKey()->create();

    (new DeliverGameKey($order->id))->failed(new RuntimeException('boom'));

    expect($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->key->fresh()->status)->toBe(KeyStatus::Reserved);
});

it('never releases a sold key and never fails a delivered order', function () {
    $order = Order::factory()->delivered()->create();

    expect(fn () => DB::transaction(fn () => app(InventoryAllocator::class)->release($order->key, $order)))->toThrow(LogicException::class)
        ->and(app(FailOrder::class)->handle($order->id, FailureCode::OutOfStock))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->key->fresh()->status)->toBe(KeyStatus::Sold);
});

it('enforces the order state machine', function () {
    $order = Order::factory()->delivered()->create();

    expect(fn () => DB::transaction(fn () => $order->transitionTo(OrderStatus::Pending)))->toThrow(InvalidOrderTransition::class)
        ->and(fn () => DB::transaction(fn () => $order->transitionTo(OrderStatus::Failed)))->toThrow(InvalidOrderTransition::class);
});
