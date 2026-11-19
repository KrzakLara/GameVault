<?php

/*
 * Transaction semantics of checkout, observed through the REAL database queue driver.
 * (Queue::fake() records a job the moment dispatch() is called and ignores afterCommit(), so it
 * cannot show whether a job would be visible to a worker before the commit.)
 */

use App\Actions\Checkout\PlaceOrder;
use App\Enums\KeyStatus;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Invariants;

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->buyer = User::factory()->create();
    $this->game = Game::factory()->create();
    $this->key = GameKey::factory()->for($this->game)->create();
});

function fulfillmentJobs(): int
{
    return DB::table('jobs')->where('queue', 'fulfillment')->count();
}

it('rolls back completely when anything fails after the key was reserved, and the same request can be retried', function () {
    $idempotencyKey = (string) Str::uuid();
    OrderEvent::creating(fn () => throw new RuntimeException('simulated failure after reservation'));

    expect(fn () => app(PlaceOrder::class)->handle($this->buyer, $this->game, $idempotencyKey))->toThrow(RuntimeException::class);

    expect(Order::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and($this->key->fresh()->status)->toBe(KeyStatus::Available)
        ->and($this->key->fresh()->order_id)->toBeNull()
        ->and(fulfillmentJobs())->toBe(0);

    OrderEvent::flushEventListeners();
    $this->actingAs($this->buyer)->postJson(route('orders.store', $this->game), ['idempotency_key' => $idempotencyKey])->assertCreated();
    expect(fulfillmentJobs())->toBe(1);
    Invariants::assertInventoryConsistent();
});

it('makes the fulfillment job visible to workers only after the checkout commits, and never after a rollback', function () {
    GameKey::factory()->for($this->game)->create();

    DB::transaction(function () {
        app(PlaceOrder::class)->handle($this->buyer, $this->game, (string) Str::uuid());
        expect(fulfillmentJobs())->toBe(0); // not committed yet: no worker may see this order
    });
    expect(fulfillmentJobs())->toBe(1);

    try {
        DB::transaction(function () {
            app(PlaceOrder::class)->handle($this->buyer, $this->game, (string) Str::uuid());
            throw new RuntimeException('the surrounding transaction fails after the checkout');
        });
    } catch (RuntimeException) {
    }

    expect(fulfillmentJobs())->toBe(1)->and(Order::count())->toBe(1);
});
