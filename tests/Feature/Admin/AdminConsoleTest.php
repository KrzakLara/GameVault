<?php

use App\Enums\FailureCode;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function outboxRow(Order $order, array $attributes): OutboxMessage
{
    return OutboxMessage::forceCreate($attributes + [
        'event_id' => 'evt-'.uniqid(), 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $order->id,
        'payload' => ['order_id' => $order->id], 'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
    ]);
}

it('shows live database aggregates on the overview, including low stock and recent failures', function () {
    config(['gamevault.low_stock_threshold' => 3]);
    $low = Game::factory()->create(['name' => 'Almost Gone']);
    GameKey::factory()->for($low)->create();
    $plenty = Game::factory()->create();
    GameKey::factory()->count(5)->for($plenty)->create();
    Order::factory()->delivered()->create();
    Order::factory()->create();
    $failed = Order::factory()->failed(FailureCode::OutOfStock)->create();

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Dashboard')
        ->where('orders', ['total' => 3, 'delivered' => 1, 'in_flight' => 1, 'failed' => 1, 'cancelled' => 0])
        ->where('inventory', ['available' => 6, 'reserved' => 0, 'sold' => 1])
        ->where('lowStock.threshold', 3)
        ->where('lowStock.games', fn ($games) => collect($games)->pluck('name')->contains('Almost Gone') && ! collect($games)->pluck('slug')->contains($plenty->slug))
        ->where('recentFailures.0.public_id', $failed->public_id)
        ->has('outbox.counts'));
});

it('lists inventory per game with counts only, filters low and sold-out stock, and never loads key codes', function () {
    config(['gamevault.low_stock_threshold' => 3]);
    $out = Game::factory()->create(['name' => 'Out']);
    $low = Game::factory()->create(['name' => 'Low']);
    GameKey::factory()->for($low)->create(['code' => 'INVENTORY-SECRET-1']);
    $ok = Game::factory()->create(['name' => 'Fine']);
    GameKey::factory()->count(4)->for($ok)->create();
    Order::factory()->for($ok)->delivered()->create();

    $response = $this->actingAs($this->admin)->get(route('admin.inventory.index'));
    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/Inventory')
        ->where('games.data.0.name', 'Out') // ordered by available stock
        ->where('games.data.2', ['slug' => $ok->slug, 'name' => 'Fine', 'platform' => 'Steam', 'is_active' => true, 'available' => 4, 'reserved' => 0, 'sold' => 1]));
    expect($response->getContent())->not->toContain('INVENTORY-SECRET-1');

    $names = fn (string $stock) => collect($this->actingAs($this->admin)->get(route('admin.inventory.index', ['stock' => $stock]))->viewData('page')['props']['games']['data'])->pluck('name')->all();
    expect($names('low'))->toBe(['Out', 'Low'])->and($names('out'))->toBe(['Out']);
});

it('filters the outbox by status, including "retrying" (pending after a failed attempt)', function () {
    $order = Order::factory()->delivered()->create();
    outboxRow($order, ['event_id' => 'fresh']);
    outboxRow($order, ['event_id' => 'retry', 'attempts' => 2, 'last_error' => 'RuntimeException: smtp down']);
    outboxRow($order, ['event_id' => 'dead', 'status' => 'dead_letter', 'attempts' => 8, 'dead_lettered_at' => now()]);
    outboxRow($order, ['event_id' => 'done', 'status' => 'published', 'attempts' => 1, 'published_at' => now()]);

    $events = fn (string $status) => collect($this->actingAs($this->admin)->get(route('admin.outbox.index', ['status' => $status]))->viewData('page')['props']['messages']['data'])->pluck('event_id')->sort()->values()->all();

    expect($events('all'))->toBe(['dead', 'done', 'fresh', 'retry'])
        ->and($events('retrying'))->toBe(['retry'])
        ->and($events('dead_letter'))->toBe(['dead'])
        ->and($events('published'))->toBe(['done']);

    $this->actingAs($this->admin)->get(route('admin.outbox.index'))->assertInertia(fn (Assert $page) => $page
        ->where('retrying', 1)
        ->where('statusFilters', ['all', 'pending', 'retrying', 'processing', 'published', 'dead_letter'])
        ->missing('messages.data.0.lock_token')
        ->missing('messages.data.0.payload'));
});

it('requeues every dead letter in one audited action', function () {
    $order = Order::factory()->delivered()->create();
    outboxRow($order, ['status' => 'dead_letter', 'attempts' => 8, 'dead_lettered_at' => now()]);
    outboxRow($order, ['status' => 'dead_letter', 'attempts' => 8, 'dead_lettered_at' => now()]);

    $this->actingAs($this->admin)->postJson(route('admin.outbox.requeue-all'))->assertOk()->assertJsonPath('requeued', 2);

    expect(OutboxMessage::where('status', 'dead_letter')->count())->toBe(0)
        ->and(AuditLog::where('action', 'outbox.requeue')->sole()->meta)->toMatchArray(['count' => 2]);
});

it('searches orders by customer e-mail prefix as well as order id, treating wildcards literally', function () {
    $alice = User::factory()->create(['email' => 'alice@example.com']);
    $aliceOrder = Order::factory()->for($alice)->create();
    Order::factory()->for(User::factory()->create(['email' => 'bob@example.com']))->create();

    $ids = fn (string $q) => collect($this->actingAs($this->admin)->get(route('admin.orders.index', ['q' => $q]))->viewData('page')['props']['orders']['data'])->pluck('public_id')->all();

    expect($ids('alice@'))->toBe([$aliceOrder->public_id])
        ->and($ids(substr($aliceOrder->public_id, 0, 20)))->toBe([$aliceOrder->public_id])
        ->and($ids('%'))->toBe([]);
});

it('keeps every new admin page behind the admin gate', function (string $name) {
    $this->get(route($name))->assertRedirect(route('login')); // guest first: actingAs() persists

    $customer = User::factory()->create();
    $this->actingAs($customer)->get(route($name))->assertForbidden();
    $this->actingAs($customer)->postJson(route('admin.outbox.requeue-all'))->assertForbidden();
})->with(['admin.dashboard', 'admin.inventory.index']);
