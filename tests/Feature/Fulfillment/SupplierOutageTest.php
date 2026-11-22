<?php

use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Fulfillment\SimulatedKeyProvisioner;
use App\Services\Fulfillment\SupplierUnavailableException;
use Illuminate\Support\Facades\Cache;

/*
 * Fault injection used to observe retries on a live Horizon stack (scripts/verify-horizon.sh).
 * A simulated outage is a transient failure: the claim is relinquished and the key stays
 * reserved, so the queue's retry can finish the order once the supplier is back.
 */

it('fails every hand-off while the simulated outage is on, then delivers once it is off', function () {
    $order = Order::factory()->withReservedKey()->create();
    $this->artisan('gamevault:supplier-outage', ['state' => 'on'])->assertSuccessful();

    expect(fn () => app(FulfillOrder::class)->handle($order->id))->toThrow(SupplierUnavailableException::class);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Processing)
        ->and($order->lease_expires_at->lte(now()))->toBeTrue() // relinquished: a retry may take over at once
        ->and($order->key->status)->toBe(KeyStatus::Reserved);

    $this->artisan('gamevault:supplier-outage', ['state' => 'off'])->assertSuccessful();
    app(FulfillOrder::class)->handle($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('expires the simulated outage on its own', function () {
    $this->artisan('gamevault:supplier-outage', ['state' => 'on', '--minutes' => 2])->assertSuccessful();
    expect(Cache::get(SimulatedKeyProvisioner::OUTAGE_FLAG))->toBeTrue();

    $this->travel(3)->minutes();

    expect(Cache::get(SimulatedKeyProvisioner::OUTAGE_FLAG))->toBeNull();
});

it('can never be switched on, or take effect, in production', function () {
    Cache::put(SimulatedKeyProvisioner::OUTAGE_FLAG, true, 600); // e.g. left over in a shared cache
    $this->app['env'] = 'production';
    $order = Order::factory()->withReservedKey()->create();

    $this->artisan('gamevault:supplier-outage', ['state' => 'off'])->assertFailed();
    app(FulfillOrder::class)->handle($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('rejects an unknown state', function () {
    $this->artisan('gamevault:supplier-outage', ['state' => 'maybe'])->assertExitCode(2);
    expect(Cache::get(SimulatedKeyProvisioner::OUTAGE_FLAG))->toBeNull();
});
