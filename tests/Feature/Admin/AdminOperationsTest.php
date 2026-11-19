<?php

use App\Enums\FailureCode;
use App\Enums\InventoryMovementType;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Enums\OutboxStatus;
use App\Jobs\DeliverGameKey;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Invariants;

beforeEach(function () {
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
});

describe('retry', function () {
    it('re-queues a retryable failed order with a fresh attempt budget, a reserved key, a job and an audit entry', function () {
        $order = Order::factory()->failed(FailureCode::OutOfStock)->create(['attempts' => 4]);
        $key = GameKey::factory()->for($order->game)->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.retry', $order), ['note' => 'restocked'])
            ->assertStatus(202)->assertJsonPath('order.status', 'pending');

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Pending)
            ->and($order->attempts)->toBe(0)
            ->and($order->failure_code)->toBeNull()
            ->and($key->fresh()->order_id)->toBe($order->id);
        Queue::assertPushed(DeliverGameKey::class, fn ($job) => $job->orderId === $order->id);

        $audit = AuditLog::where('action', 'order.retry')->sole();
        expect($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->subject_id)->toBe($order->id)
            ->and($audit->meta)->toMatchArray(['previous_failure' => 'out_of_stock', 'note' => 'restocked', 'via' => 'http']);
        Invariants::assertInventoryConsistent();
    });

    it('refuses a failure that needs investigation (key_integrity)', function () {
        $order = Order::factory()->failed(FailureCode::KeyIntegrity)->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.retry', $order))->assertStatus(422)->assertJsonValidationErrors('order');

        expect($order->fresh()->status)->toBe(OrderStatus::Failed)->and(AuditLog::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('refuses when there is no stock and changes nothing', function () {
        $order = Order::factory()->failed(FailureCode::OutOfStock)->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.retry', $order))->assertStatus(422);

        expect($order->fresh()->status)->toBe(OrderStatus::Failed)
            ->and(AuditLog::count())->toBe(0)
            ->and($order->events()->count())->toBe(0); // the transition was rolled back too
        Queue::assertNothingPushed();
    });

    it('is safe to click twice', function () {
        $order = Order::factory()->failed(FailureCode::OutOfStock)->create();
        GameKey::factory()->count(2)->for($order->game)->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.retry', $order))->assertStatus(202);
        $this->actingAs($this->admin)->postJson(route('admin.orders.retry', $order))->assertStatus(422);

        expect(GameKey::where('status', KeyStatus::Reserved)->count())->toBe(1);
        Queue::assertPushedTimes(DeliverGameKey::class, 1);
    });
});

describe('cancel', function () {
    it('requires a written reason', function (?string $reason) {
        $order = Order::factory()->failed()->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.cancel', $order), ['reason' => $reason])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        expect($order->fresh()->status)->toBe(OrderStatus::Failed);
    })->with(['missing' => null, 'too short' => 'no']);

    it('closes a failed order permanently, keeps a quarantined key off the shelf, and audits the reason', function () {
        $order = Order::factory()->failed(FailureCode::KeyIntegrity)->withReservedKey()->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.cancel', $order), ['reason' => 'Corrupt key, supplier informed'])->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->fresh()->cancelled_at)->not->toBeNull()
            ->and($order->key->fresh()->status)->toBe(KeyStatus::Reserved)
            ->and(AuditLog::where('action', 'order.cancel')->sole()->meta)
            ->toMatchArray(['reason' => 'Corrupt key, supplier informed', 'quarantined_key_retained' => true]);
    });

    it('refuses to cancel an order that is not failed', function () {
        $order = Order::factory()->delivered()->create();

        $this->actingAs($this->admin)->postJson(route('admin.orders.cancel', $order), ['reason' => 'changed my mind'])->assertStatus(422);
        expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
    });
});

describe('key import', function () {
    it('imports new keys, skips ones already in stock, normalises input, and audits counts only', function () {
        $game = Game::factory()->create();
        GameKey::factory()->create(['code' => 'EXIST-12345']); // in stock, for another game

        $this->actingAs($this->admin)->postJson(route('admin.games.keys.store', $game), ['codes' => ['new01-aaaaa', 'EXIST-12345', ' NEW02-BBBBB ']])
            ->assertCreated()->assertExactJson(['imported' => 2, 'duplicates' => 1]);

        expect($game->keys()->get()->map(fn (GameKey $k) => $k->code)->sort()->values()->all())->toBe(['NEW01-AAAAA', 'NEW02-BBBBB'])
            ->and(InventoryMovement::where('type', InventoryMovementType::Imported)->count())->toBe(2);

        $audit = AuditLog::where('action', 'inventory.import')->sole();
        expect($audit->meta)->toMatchArray(['imported' => 2, 'duplicates' => 1, 'submitted' => 3])
            ->and(json_encode($audit->toArray()))->not->toContain('NEW01')->not->toContain('EXIST-12345');
    });

    it('rejects malformed, duplicate or oversized input', function (array $codes) {
        $game = Game::factory()->create();

        $this->actingAs($this->admin)->postJson(route('admin.games.keys.store', $game), ['codes' => $codes])->assertStatus(422);
        expect($game->keys()->count())->toBe(0)->and(AuditLog::count())->toBe(0);
    })->with([
        'empty' => [[]],
        'malformed' => [['has spaces in it']],
        'markup' => [['<b>ABCDE-12345</b>']],
        'duplicate in batch' => [['ABCDE-12345', 'abcde-12345']],
        'too many' => [array_map(fn ($i) => sprintf('KEY%05d-AAAAA', $i), range(1, 501))],
    ]);
});

describe('outbox', function () {
    it('requeues a dead-lettered message with a fresh budget and audits it', function () {
        $order = Order::factory()->delivered()->create();
        $message = OutboxMessage::forceCreate(['event_id' => "order.delivered:{$order->id}", 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $order->id, 'payload' => ['order_id' => $order->id], 'status' => 'dead_letter', 'attempts' => 8, 'available_at' => now(), 'dead_lettered_at' => now()]);

        $this->actingAs($this->admin)->postJson(route('admin.outbox.requeue', $message))->assertOk()->assertJsonPath('requeued', 1);

        expect($message->fresh()->status)->toBe(OutboxStatus::Pending)
            ->and($message->fresh()->attempts)->toBe(0)
            ->and(AuditLog::where('action', 'outbox.requeue')->sole()->meta)->toMatchArray(['message_ids' => [$message->id], 'count' => 1]);
    });

    it('refuses to requeue a message that is not dead-lettered', function () {
        $order = Order::factory()->delivered()->create();
        $message = OutboxMessage::forceCreate(['event_id' => 'e1', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $order->id, 'payload' => ['order_id' => $order->id], 'status' => 'published', 'available_at' => now(), 'published_at' => now()]);

        $this->actingAs($this->admin)->postJson(route('admin.outbox.requeue', $message))->assertStatus(409);
        expect($message->fresh()->status)->toBe(OutboxStatus::Published);
    });
});

it('filters the admin order list by status and failure code', function () {
    $wanted = Order::factory()->failed(FailureCode::KeyIntegrity)->create();
    Order::factory()->failed(FailureCode::OutOfStock)->create();
    Order::factory()->delivered()->create();

    $this->actingAs($this->admin)->get(route('admin.orders.index', ['status' => 'failed', 'failure_code' => 'key_integrity']))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.public_id', $wanted->public_id));
});

it('grants and revokes admin rights only from the CLI, with an audit trail', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('gamevault:admin ops@example.com')->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeTrue()->and(AuditLog::where('action', 'user.admin_granted')->sole()->meta)->toMatchArray(['via' => 'console']);

    $this->artisan('gamevault:admin ops@example.com --revoke')->assertSuccessful();
    expect($user->fresh()->is_admin)->toBeFalse();

    $this->artisan('gamevault:admin nobody@example.com')->assertFailed();
});
