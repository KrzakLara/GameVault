<?php

/*
 * Transaction-boundary rules that only show with committed data and a real top-level
 * transaction (RefreshDatabase keeps a transaction open, which would hide them).
 */

use App\Actions\Fulfillment\FulfillmentClaim;
use App\Actions\Fulfillment\FulfillOrder;
use App\Models\Order;
use App\Services\Fulfillment\KeyProvisioner;
use App\Services\Inventory\InventoryAllocator;
use App\Services\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithCommittedData;

uses(InteractsWithCommittedData::class);

it('refuses inventory and outbox writes outside a database transaction', function () {
    $order = Order::factory()->withReservedKey()->create();
    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => app(InventoryAllocator::class)->reserve($order))->toThrow(LogicException::class, 'transaction')
        ->and(fn () => app(InventoryAllocator::class)->release($order->key, $order))->toThrow(LogicException::class, 'transaction')
        ->and(fn () => app(Outbox::class)->record('order.delivered', 'x', $order, ['order_id' => $order->id]))->toThrow(LogicException::class, 'transaction');
});

it('calls the external provisioner with no transaction open, between two committed steps', function () {
    Queue::fake();
    $order = Order::factory()->withReservedKey()->create();
    $seen = new ArrayObject;

    app()->instance(KeyProvisioner::class, new class($seen) implements KeyProvisioner
    {
        public function __construct(private ArrayObject $seen) {}

        public function provision(FulfillmentClaim $claim): void
        {
            $this->seen['level'] = DB::transactionLevel();
            // The claim is already committed: an independent connection can see it.
            config(['database.connections.observer' => config('database.connections.'.config('database.default'))]);
            $this->seen['status_seen_by_other_session'] = DB::connection('observer')->table('orders')->where('id', $claim->orderId)->value('status');
        }
    });

    app(FulfillOrder::class)->handle($order->id);

    expect($seen['level'])->toBe(0)
        ->and($seen['status_seen_by_other_session'])->toBe('processing');
    DB::purge('observer');
});
