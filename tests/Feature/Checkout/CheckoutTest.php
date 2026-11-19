<?php

use App\Actions\Checkout\PlaceOrder;
use App\Enums\InventoryMovementType;
use App\Enums\KeyStatus;
use App\Exceptions\CheckoutException;
use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Invariants;

beforeEach(function () {
    Queue::fake();
    $this->buyer = User::factory()->create();
    $this->game = Game::factory()->create(['price_cents' => 2999]);
    $this->key = GameKey::factory()->for($this->game)->create();
});

function checkout(object $test, Game $game, ?string $key = null, ?User $as = null, array $extra = []): TestResponse
{
    return $test->actingAs($as ?? $test->buyer)->postJson(route('orders.store', $game), ['idempotency_key' => $key ?? (string) Str::uuid(), ...$extra]);
}

it('accepts a checkout: one order, one reserved key, one movement, a timeline entry, and a job dispatched after commit', function () {
    $response = checkout($this, $this->game);

    $order = Order::sole();
    $response->assertCreated()
        ->assertHeader('Location', route('orders.show', $order))
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('id', $order->public_id)
        ->assertJsonPath('status', 'pending')
        ->assertJsonMissingPath('key');

    expect($order->user_id)->toBe($this->buyer->id)
        ->and($this->key->fresh()->status)->toBe(KeyStatus::Reserved)
        ->and($this->key->fresh()->order_id)->toBe($order->id)
        ->and(InventoryMovement::sole()->type)->toBe(InventoryMovementType::Reserved)
        ->and(OrderEvent::where('order_id', $order->id)->where('type', 'checkout_accepted')->count())->toBe(1);
    Queue::assertPushedTimes(DeliverGameKey::class, 1);
    Invariants::assertInventoryConsistent();
});

it('redirects browser (Inertia) checkouts to the order page', function () {
    $this->actingAs($this->buyer)->post(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])
        ->assertRedirect(route('orders.show', Order::sole()))
        ->assertSessionHas('success');
});

it('replays a repeated request: same order, 200, no new rows and no second job', function () {
    $key = (string) Str::uuid();
    $first = checkout($this, $this->game, $key)->assertCreated();

    $second = checkout($this, $this->game, $key);

    $second->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $first->json('id'));
    expect(Order::count())->toBe(1)
        ->and(InventoryMovement::count())->toBe(1)
        ->and(OrderEvent::count())->toBe(1);
    Queue::assertPushedTimes(DeliverGameKey::class, 1);
});

it('scopes idempotency keys per user: the same key from two users makes two orders', function () {
    GameKey::factory()->for($this->game)->create();
    $key = (string) Str::uuid();

    checkout($this, $this->game, $key)->assertCreated();
    checkout($this, $this->game, $key, User::factory()->create())->assertCreated();

    expect(Order::count())->toBe(2)->and(Order::pluck('idempotency_key')->unique()->all())->toBe([$key]);
});

it('rejects a reused key with a different payload (another game) and changes nothing', function () {
    $other = Game::factory()->create();
    GameKey::factory()->for($other)->create();
    $key = (string) Str::uuid();
    checkout($this, $this->game, $key)->assertCreated();

    checkout($this, $other, $key)->assertStatus(422)->assertJsonPath('error', 'idempotency_conflict');

    expect(Order::count())->toBe(1)->and($other->keys()->first()->status)->toBe(KeyStatus::Available);
    Queue::assertPushedTimes(DeliverGameKey::class, 1);
});

it('accepts the Idempotency-Key header and rejects a header/body mismatch', function () {
    $key = (string) Str::uuid();

    $this->actingAs($this->buyer)->withHeader('Idempotency-Key', $key)->postJson(route('orders.store', $this->game))->assertCreated();
    expect(Order::sole()->idempotency_key)->toBe($key);

    $this->actingAs($this->buyer)->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
});

it('validates the idempotency key', function (mixed $key) {
    $this->actingAs($this->buyer)->postJson(route('orders.store', $this->game), ['idempotency_key' => $key])
        ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

    expect(Order::count())->toBe(0);
})->with([
    'missing' => null,
    'too short' => 'abc123',
    'too long' => str_repeat('a', 65),
    'illegal characters' => 'abcd efgh ijkl mnop',
    'markup' => '<script>alert(1)</script>xx',
    'not a string' => [['nested']],
]);

it('rejects a sold-out game with 409 and leaves no order, no movement and no job behind', function () {
    $this->key->delete();

    checkout($this, $this->game)->assertStatus(409)->assertJsonPath('error', 'out_of_stock');

    expect(Order::count())->toBe(0)->and(InventoryMovement::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not burn the idempotency key on a rejection: the same request succeeds after a restock', function () {
    $this->key->delete();
    $key = (string) Str::uuid();
    checkout($this, $this->game, $key)->assertStatus(409);

    GameKey::factory()->for($this->game)->create();

    checkout($this, $this->game, $key)->assertCreated();
    expect(Order::count())->toBe(1);
});

it('rejects inactive games with 404 without creating an order', function () {
    $this->game->update(['is_active' => false]);

    checkout($this, $this->game)->assertNotFound()->assertJsonPath('error', 'unavailable');

    expect(Order::count())->toBe(0)->and($this->key->fresh()->status)->toBe(KeyStatus::Available);
});

it('snapshots the server-side price; later price changes affect neither the order nor its replay', function () {
    $key = (string) Str::uuid();
    checkout($this, $this->game, $key, extra: ['price_cents' => 1])->assertCreated()->assertJsonPath('price_cents', 2999);

    $this->game->update(['price_cents' => 9999]);

    checkout($this, $this->game, $key)->assertOk()->assertJsonPath('price_cents', 2999);
    expect(Order::sole()->price_cents)->toBe(2999);
});

it('reports out of stock through the action with a stable machine-readable reason', function () {
    $this->key->delete();

    try {
        app(PlaceOrder::class)->handle($this->buyer, $this->game, (string) Str::uuid());
        $this->fail('Expected a CheckoutException');
    } catch (CheckoutException $e) {
        expect($e->reason)->toBe('out_of_stock')->and($e->status)->toBe(409);
    }
});
