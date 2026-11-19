<?php

/*
 * The Vue app's TypeScript contracts (resources/js/types/models.ts) mirror these resources.
 * If a resource gains, loses or renames a field, this test fails and points at the type to
 * update, so frontend and backend cannot drift apart silently. It also pins that no secret
 * field ever becomes part of a contract.
 */

use App\Http\Resources\Admin\AdminOrderResource;
use App\Http\Resources\Admin\InventoryGameResource;
use App\Http\Resources\Admin\OutboxMessageResource;
use App\Http\Resources\GameResource;
use App\Http\Resources\OrderResource;
use App\Models\Game;
use App\Models\Order;
use App\Models\OutboxMessage;
use Illuminate\Http\Request;

function keysOf(array $resource): array
{
    $keys = array_keys($resource);
    sort($keys);

    return $keys;
}

it('matches the Game type', function () {
    $game = Game::query()->withAvailableStock()->findOrFail(Game::factory()->create()->id);

    expect(keysOf(GameResource::make($game)->resolve(new Request)))
        ->toBe(['cover_url', 'currency', 'description', 'genre', 'is_featured', 'name', 'platform', 'price_cents', 'slug', 'stock']);
});

it('matches the Order type and exposes no secrets', function () {
    $order = Order::factory()->delivered('CONTRACT-SECRET-1')->create()->load(['game', 'events']);
    $resource = OrderResource::make($order)->resolve(new Request);

    expect(keysOf($resource))->toBe(['can_reveal', 'created_at', 'currency', 'delivered_at', 'failure_message', 'game', 'id', 'price_cents', 'status', 'timeline'])
        ->and(keysOf($resource['game']))->toBe(['cover_url', 'name', 'platform', 'slug'])
        ->and(json_encode($resource))->not->toContain('CONTRACT-SECRET-1')->not->toContain($order->idempotency_key);
});

it('matches the AdminOrder type and never includes the key code', function () {
    $order = Order::factory()->delivered('ADMIN-CONTRACT-SECRET')->create()->load(['game', 'user', 'key']);
    $resource = AdminOrderResource::make($order)->resolve(new Request);

    expect(keysOf($resource))->toBe([
        'attempts', 'cancelled_at', 'created_at', 'currency', 'delivered_at', 'failed_at', 'failure_code', 'game', 'id', 'key',
        'lease_expires_at', 'price_cents', 'processing_started_at', 'public_id', 'retryable', 'status', 'user',
    ])->and(keysOf($resource['key']))->toBe(['id', 'reserved_at', 'sold_at', 'status'])
        ->and(json_encode($resource))->not->toContain('ADMIN-CONTRACT-SECRET');
});

it('matches the InventoryGame and OutboxMessage types', function () {
    $game = Game::query()->withCount(['keys as available', 'keys as reserved', 'keys as sold'])->findOrFail(Game::factory()->create()->id);
    $order = Order::factory()->create();
    $message = OutboxMessage::forceCreate(['event_id' => 'e', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $order->id, 'payload' => ['order_id' => $order->id], 'status' => 'pending', 'available_at' => now()]);

    expect(keysOf(InventoryGameResource::make($game)->resolve(new Request)))->toBe(['available', 'is_active', 'name', 'platform', 'reserved', 'slug', 'sold'])
        ->and(keysOf(OutboxMessageResource::make($message->fresh())->resolve(new Request)))->toBe([
            'attempts', 'available_at', 'created_at', 'dead_lettered_at', 'event_id', 'id', 'last_error', 'locked_until', 'published_at', 'status', 'type', 'updated_at',
        ]);
});
