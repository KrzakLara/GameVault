<?php

// The original overlay scenarios, updated to the hardened APIs. Each keeps its original intent.

use App\Enums\FailureCode;
use App\Enums\InventoryMovementType;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function runDelivery(int $orderId): void
{
    app()->call([new DeliverGameKey($orderId), 'handle']);
}

it('creates a pending order and queues delivery', function () {
    Queue::fake();
    $user = User::factory()->create();
    $game = Game::factory()->create();
    GameKey::factory()->for($game)->create();

    $this->actingAs($user)->post(route('orders.store', $game), ['idempotency_key' => (string) Str::uuid()])
        ->assertRedirect(route('orders.show', Order::sole()));

    $order = Order::sole();
    expect($order->status)->toBe(OrderStatus::Pending);
    Queue::assertPushed(DeliverGameKey::class, fn (DeliverGameKey $job) => $job->orderId === $order->id);
});

it('delivers an available key and remains idempotent on retry', function () {
    $order = Order::factory()->withReservedKey()->create();

    runDelivery($order->id);
    runDelivery($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->key->fresh()->status)->toBe(KeyStatus::Sold)
        ->and(InventoryMovement::where('type', InventoryMovementType::Sold)->count())->toBe(1);
});

it('fails gracefully when inventory is empty', function () {
    $order = Order::factory()->create(); // legacy order: no reservation, no stock

    runDelivery($order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->fresh()->failure_code)->toBe(FailureCode::OutOfStock);
});

it('never assigns the same key to two sequential orders', function () {
    $game = Game::factory()->create();
    $key = GameKey::factory()->for($game)->create();
    $first = Order::factory()->for($game)->create();
    $second = Order::factory()->for($game)->create();

    runDelivery($first->id);
    runDelivery($second->id);

    expect($first->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($second->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($key->fresh()->order_id)->toBe($first->id);
});

it('forbids revealing another users key', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $order = Order::factory()->for($owner)->delivered('SECRET-KEY')->create();

    // 404, not 403: a stranger cannot even confirm that the order exists.
    $this->actingAs($stranger)->postJson(route('orders.reveal', $order))->assertNotFound();
    $this->actingAs($owner)->postJson(route('orders.reveal', $order))->assertOk()->assertJsonPath('key', 'SECRET-KEY');
});

it('encrypts stored keys at rest', function () {
    $key = GameKey::factory()->create(['code' => 'NEVER-PLAIN-TEXT']);

    expect($key->getRawOriginal('code'))->not->toContain('NEVER-PLAIN-TEXT')
        ->and($key->fresh()->code)->toBe('NEVER-PLAIN-TEXT');
});
