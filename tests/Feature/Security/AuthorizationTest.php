<?php

use App\Enums\FailureCode;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Every protected route, with placeholders filled from a fixture order. */
function customerRoutes(): array
{
    return [
        'customer: my orders' => ['GET', '/my-orders'],
        'customer: order detail' => ['GET', '/my-orders/{order}'],
        'customer: reveal key' => ['POST', '/my-orders/{order}/reveal'],
        'customer: checkout' => ['POST', '/games/{game}/checkout'],
    ];
}

function adminRoutes(): array
{
    return [
        'admin: order list' => ['GET', '/admin/orders'],
        'admin: order detail' => ['GET', '/admin/orders/{order}'],
        'admin: retry' => ['POST', '/admin/orders/{order}/retry'],
        'admin: cancel' => ['POST', '/admin/orders/{order}/cancel'],
        'admin: import keys' => ['POST', '/admin/games/{game}/keys'],
        'admin: outbox' => ['GET', '/admin/outbox'],
        'admin: requeue' => ['POST', '/admin/outbox/{message}/requeue'],
    ];
}

function routeFor(string $uri, Order $order, ?OutboxMessage $message = null): string
{
    return str_replace(['{order}', '{game}', '{message}'], [$order->public_id, $order->game->slug, (string) ($message?->id ?? 1)], $uri);
}

describe('guests', function () {
    it('are sent to the login page', function (string $method, string $uri) {
        $order = Order::factory()->create();

        $this->call($method, routeFor($uri, $order))->assertRedirect(route('login'));
    })->with([...customerRoutes(), ...adminRoutes()]);

    it('get 401 from JSON requests', function () {
        $order = Order::factory()->delivered()->create();

        $this->postJson(route('orders.reveal', $order))->assertUnauthorized();
    });
});

describe("another customer's order (IDOR)", function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->order = Order::factory()->for($this->owner)->delivered('OWNER-SECRET-KEY')->create();
    });

    it('cannot be viewed, and its existence is not even confirmed (404)', function () {
        $this->actingAs($this->stranger)->get(route('orders.show', $this->order))->assertNotFound();
        $this->actingAs($this->stranger)->getJson(route('orders.show', $this->order))->assertNotFound();
    });

    it('cannot have its key revealed', function () {
        $response = $this->actingAs($this->stranger)->postJson(route('orders.reveal', $this->order));

        $response->assertNotFound();
        expect($response->getContent())->not->toContain('OWNER-SECRET-KEY')
            ->and(AuditLog::where('action', 'order.key_revealed')->exists())->toBeFalse();
    });

    it("never appears in the stranger's order list", function () {
        Order::factory()->for($this->stranger)->create();

        $this->actingAs($this->stranger)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->component('orders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', fn (string $id) => $id !== $this->order->public_id));
    });

    it('cannot be retried or cancelled by a non-admin', function () {
        $failed = Order::factory()->for($this->owner)->failed(FailureCode::OutOfStock)->create();

        $this->actingAs($this->stranger)->postJson(route('admin.orders.retry', $failed))->assertForbidden();
        $this->actingAs($this->owner)->postJson(route('admin.orders.cancel', $failed), ['reason' => 'mine anyway'])->assertForbidden();

        expect($failed->fresh()->status)->toBe(OrderStatus::Failed);
    });

    it('cannot be addressed by its internal numeric id', function () {
        $this->actingAs($this->owner)->get('/my-orders/'.$this->order->id)->assertNotFound();
    });

    it('uses non-enumerable ULIDs as public identifiers', function () {
        $other = Order::factory()->create();

        expect($this->order->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($this->order->public_id)->not->toBe($other->public_id)
            ->and(route('orders.show', $this->order))->toEndWith('/my-orders/'.$this->order->public_id);
    });
});

describe('the admin area', function () {
    it('is forbidden to customers on every route, and nothing changes', function (string $method, string $uri) {
        $order = Order::factory()->failed()->create();
        $message = OutboxMessage::forceCreate(['event_id' => 'e1', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $order->id, 'payload' => ['order_id' => $order->id], 'status' => 'dead_letter', 'available_at' => now()]);
        $before = [DB::table('orders')->get(), DB::table('game_keys')->get(), DB::table('outbox_messages')->get()];

        $this->actingAs(User::factory()->create())
            ->call($method, routeFor($uri, $order, $message), ['reason' => 'trying it', 'codes' => ['ABCDE-12345']], server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertForbidden();

        expect([DB::table('orders')->get(), DB::table('game_keys')->get(), DB::table('outbox_messages')->get()])->toEqual($before);
    })->with(adminRoutes());

    it('is available to administrators', function () {
        $order = Order::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/orders/Index'));
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/orders/Show'));
        $this->actingAs($admin)->get(route('admin.outbox.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/Outbox'));
    });

    it("never lets an administrator reveal a customer's key", function () {
        $order = Order::factory()->delivered('CUSTOMER-SECRET-KEY')->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson(route('orders.reveal', $order))->assertNotFound();
        $page = $this->actingAs($admin)->get(route('admin.orders.show', $order));
        expect($page->getContent())->not->toContain('CUSTOMER-SECRET-KEY');
    });

    it('is enforced on the server even when the UI hides nothing (direct request)', function () {
        $game = Game::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.games.keys.store', $game), ['codes' => ['ABCDE-12345']])
            ->assertForbidden();

        expect($game->keys()->count())->toBe(0);
    });
});

describe('the Horizon dashboard', function () {
    it('is admin-only in every environment, including local', function (string $environment) {
        $this->app['env'] = $environment;

        $this->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertOk();
    })->with(['local', 'production']);
});
