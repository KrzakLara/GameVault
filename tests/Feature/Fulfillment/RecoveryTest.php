<?php

use App\Actions\Fulfillment\FulfillmentClaim;
use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\FailureCode;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\GameKey;
use App\Models\Order;
use App\Services\Fulfillment\KeyProvisioner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Invariants;

function staleSince(Order $order, CarbonInterface $at): Order
{
    DB::table('orders')->where('id', $order->id)->update(['updated_at' => $at]);

    return $order;
}

it('re-dispatches lost pending orders and long-expired leases, but leaves fresh, live, relinquished and finished orders alone', function () {
    Queue::fake();
    $lost = staleSince(Order::factory()->withReservedKey()->create(), now()->subMinutes(30));
    Order::factory()->withReservedKey()->create(); // fresh pending: its job is probably still queued
    $abandoned = Order::factory()->processing(now()->subMinutes(30))->withReservedKey()->create();
    Order::factory()->processing(now()->subMinute())->withReservedKey()->create(); // relinquished: its queue retry is waiting out the backoff
    Order::factory()->processing(now()->addMinute())->withReservedKey()->create(); // live worker
    staleSince(Order::factory()->delivered()->create(), now()->subDay());
    staleSince(Order::factory()->failed()->create(), now()->subDay());

    $this->artisan('gamevault:recover-stuck')->expectsOutputToContain('Re-dispatched 2 order(s).')->assertSuccessful();

    expect(Queue::pushed(DeliverGameKey::class)->map(fn (DeliverGameKey $job) => $job->orderId)->sort()->values()->all())
        ->toBe(collect([$lost->id, $abandoned->id])->sort()->values()->all());
});

it('does not start a second retry chain while a relinquished order waits out its queue backoff', function () {
    Queue::fake();
    app()->instance(KeyProvisioner::class, new class implements KeyProvisioner
    {
        public function provision(FulfillmentClaim $claim): void
        {
            throw new RuntimeException('supplier down');
        }
    });
    $order = Order::factory()->withReservedKey()->create();

    // A transient error: the worker relinquishes its lease and the queue schedules a retry.
    expect(fn () => app(FulfillOrder::class)->handle($order->id))->toThrow(RuntimeException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->fresh()->hasLiveLease())->toBeFalse();

    // A recovery sweep during the longest backoff must leave the order to that retry.
    $this->travel(max((new DeliverGameKey($order->id))->backoff()))->seconds();
    $this->artisan('gamevault:recover-stuck')->expectsOutputToContain('Re-dispatched 0 order(s).')->assertSuccessful();
    Queue::assertNothingPushed();

    // The retry never came (its message was lost): past the threshold, recovery takes over.
    $this->travel((int) config('gamevault.fulfillment.stuck_after_minutes'))->minutes();
    $this->artisan('gamevault:recover-stuck')->expectsOutputToContain('Re-dispatched 1 order(s).')->assertSuccessful();
    Queue::assertPushed(DeliverGameKey::class, fn (DeliverGameKey $job) => $job->orderId === $order->id);
});

it('dispatches nothing on a dry run', function () {
    Queue::fake();
    staleSince(Order::factory()->withReservedKey()->create(), now()->subMinutes(30));

    $this->artisan('gamevault:recover-stuck --dry-run')->expectsOutputToContain('Would re-dispatch 1 order(s).')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('recovers a lost order end to end and is safe to run repeatedly', function () {
    $order = staleSince(Order::factory()->withReservedKey()->create(), now()->subMinutes(30));

    $this->artisan('gamevault:recover-stuck')->assertSuccessful();
    $this->artisan('gamevault:recover-stuck')->expectsOutputToContain('Re-dispatched 0 order(s).');

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and(DB::table('outbox_messages')->count())->toBe(1);
    Invariants::assertAll();
});

it('bounds the lifetime of a reservation: an order that keeps failing is eventually failed and its key released', function () {
    config(['gamevault.fulfillment.max_attempts' => 2]);
    app()->instance(KeyProvisioner::class, new class implements KeyProvisioner
    {
        public function provision(FulfillmentClaim $claim): void
        {
            throw new RuntimeException('always down');
        }
    });
    $order = Order::factory()->processing(now()->subMinutes(30))->withReservedKey()->create(['attempts' => 2]);

    $this->artisan('gamevault:recover-stuck')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->failure_code)->toBe(FailureCode::AttemptsExhausted)
        ->and(GameKey::sole()->status)->toBe(KeyStatus::Available);
    Invariants::assertAll();
});
