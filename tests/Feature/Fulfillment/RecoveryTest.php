<?php

use App\Actions\Fulfillment\FulfillmentClaim;
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

it('re-dispatches lost pending orders and expired leases, but leaves fresh, live and finished orders alone', function () {
    Queue::fake();
    $lost = staleSince(Order::factory()->withReservedKey()->create(), now()->subMinutes(30));
    Order::factory()->withReservedKey()->create(); // fresh pending: its job is probably still queued
    $expired = Order::factory()->processing(now()->subMinute())->withReservedKey()->create();
    Order::factory()->processing(now()->addMinute())->withReservedKey()->create(); // live worker
    staleSince(Order::factory()->delivered()->create(), now()->subDay());
    staleSince(Order::factory()->failed()->create(), now()->subDay());

    $this->artisan('gamevault:recover-stuck')->expectsOutputToContain('Re-dispatched 2 order(s).')->assertSuccessful();

    expect(Queue::pushed(DeliverGameKey::class)->map(fn (DeliverGameKey $job) => $job->orderId)->sort()->values()->all())
        ->toBe(collect([$lost->id, $expired->id])->sort()->values()->all());
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
    $order = Order::factory()->processing(now()->subMinute())->withReservedKey()->create(['attempts' => 2]);

    $this->artisan('gamevault:recover-stuck')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->failure_code)->toBe(FailureCode::AttemptsExhausted)
        ->and(GameKey::sole()->status)->toBe(KeyStatus::Available);
    Invariants::assertAll();
});
