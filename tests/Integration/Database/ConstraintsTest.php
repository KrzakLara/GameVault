<?php

/*
 * The database has the final word. Each case performs a raw write (bypassing every application
 * check) that must be REJECTED by MySQL: CHECK constraints, UNIQUE indexes, (composite) foreign
 * keys. These are the guarantees that hold even with a bug or a manual UPDATE.
 */

use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(DB::getDriverName())->toBe('mysql');
    $this->game = Game::factory()->create();
    $this->order = Order::factory()->for($this->game)->create();
    $this->key = GameKey::factory()->for($this->game)->create();
});

function rejected(Closure $write): void
{
    expect($write)->toThrow(QueryException::class);
}

it('rejects impossible order states', function (Closure $write) {
    rejected(fn () => $write($this));
})->with([
    'unknown status' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'shipped']),
    'delivered without delivered_at' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'delivered']),
    'failed without failure code' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'failed', 'failed_at' => now()]),
    'unknown failure code' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => 'gremlins']),
    'processing without a lease' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'processing']),
    'lease on a non-processing order' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['lease_token' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'lease_expires_at' => now()]),
    'lease token without expiry' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'processing', 'lease_token' => '01JAAAAAAAAAAAAAAAAAAAAAAA']),
    'cancelled without cancelled_at' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['status' => 'cancelled']),
    'lowercase currency' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['currency' => 'eur']),
    'negative price' => fn ($t) => DB::table('orders')->where('id', $t->order->id)->update(['price_cents' => -1]),
    'duplicate public id' => fn ($t) => DB::table('orders')->where('id', Order::factory()->create()->id)->update(['public_id' => $t->order->public_id]),
    'duplicate idempotency key for one user' => fn ($t) => Order::factory()->for($t->order->user)->create(['idempotency_key' => $t->order->idempotency_key]),
]);

it('rejects impossible inventory states', function (Closure $write) {
    rejected(fn () => $write($this));
})->with([
    'available key linked to an order' => fn ($t) => DB::table('game_keys')->where('id', $t->key->id)->update(['order_id' => $t->order->id]),
    'reserved key without an order' => fn ($t) => DB::table('game_keys')->where('id', $t->key->id)->update(['status' => 'reserved', 'reserved_at' => now()]),
    'unknown key status' => fn ($t) => DB::table('game_keys')->where('id', $t->key->id)->update(['status' => 'lost']),
    'sold key without sold_at' => fn ($t) => DB::table('game_keys')->where('id', $t->key->id)->update(['status' => 'sold', 'order_id' => $t->order->id, 'reserved_at' => now()]),
    'reserved key without reserved_at' => fn ($t) => DB::table('game_keys')->where('id', $t->key->id)->update(['status' => 'reserved', 'order_id' => $t->order->id]),
    'key linked to an order of another game' => fn ($t) => DB::table('game_keys')->where('id', $t->key->id)
        ->update(['status' => 'reserved', 'order_id' => Order::factory()->create()->id, 'reserved_at' => now()]),
    'second key for the same order' => function ($t) {
        DB::table('game_keys')->where('id', $t->key->id)->update(['status' => 'reserved', 'order_id' => $t->order->id, 'reserved_at' => now()]);
        $second = GameKey::factory()->for($t->game)->create();
        DB::table('game_keys')->where('id', $second->id)->update(['status' => 'reserved', 'order_id' => $t->order->id, 'reserved_at' => now()]);
    },
    'duplicate key code (fingerprint)' => fn ($t) => DB::table('game_keys')->insert(['game_id' => $t->game->id, 'code' => 'x', 'code_hash' => $t->key->code_hash, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]),
    'movement with an unknown type' => fn ($t) => DB::table('inventory_movements')->insert(['game_id' => $t->game->id, 'game_key_id' => $t->key->id, 'type' => 'teleported']),
    'reservation movement without an order' => fn ($t) => DB::table('inventory_movements')->insert(['game_id' => $t->game->id, 'game_key_id' => $t->key->id, 'type' => 'reserved']),
    'movement for a key of another game' => fn ($t) => DB::table('inventory_movements')->insert(['game_id' => Game::factory()->create()->id, 'game_key_id' => $t->key->id, 'type' => 'imported']),
    'movement for an order of another game' => fn ($t) => DB::table('inventory_movements')->insert(['game_id' => $t->game->id, 'game_key_id' => $t->key->id, 'order_id' => Order::factory()->create()->id, 'type' => 'reserved']),
    'movement for a missing key' => fn ($t) => DB::table('inventory_movements')->insert(['game_id' => $t->game->id, 'game_key_id' => 999999, 'type' => 'imported']),
]);

it('rejects impossible outbox states', function (array $row) {
    rejected(fn () => DB::table('outbox_messages')->insert($row + [
        'event_id' => 'evt-'.uniqid(), 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => 1,
        'payload' => '{}', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));
})->with([
    'unknown status' => [['status' => 'lost']],
    'legacy "failed" status' => [['status' => 'failed']],
    'processing without a fencing token' => [['status' => 'processing']],
    'token on a pending message' => [['status' => 'pending', 'lock_token' => '01JAAAAAAAAAAAAAAAAAAAAAAA']],
    'published without published_at' => [['status' => 'published']],
]);

it('rejects a second outbox message for the same business event', function () {
    $row = ['event_id' => 'order.delivered:1', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => 1, 'payload' => '{}', 'status' => 'pending', 'available_at' => now()];
    DB::table('outbox_messages')->insert($row);

    rejected(fn () => DB::table('outbox_messages')->insert($row));
});

it('prevents orphans: orders, keys and games with history cannot be deleted', function () {
    $delivered = Order::factory()->delivered()->create();

    rejected(fn () => DB::table('orders')->where('id', $delivered->id)->delete());          // owns a key + movements
    rejected(fn () => DB::table('game_keys')->where('id', $delivered->key->id)->delete());   // has movements
    rejected(fn () => DB::table('games')->where('id', $delivered->game_id)->delete());      // has keys and orders
});

it('anonymises orders instead of deleting them when a user deletes their account', function () {
    $delivered = Order::factory()->delivered()->create();
    $user = $delivered->user;

    $user->delete();

    expect($delivered->fresh())->not->toBeNull()
        ->and($delivered->fresh()->user_id)->toBeNull()
        ->and($delivered->key->fresh()->order_id)->toBe($delivered->id)
        ->and(User::find($user->id))->toBeNull();
});

it('accepts every state the application itself produces', function () {
    expect(fn () => [
        Order::factory()->delivered()->create(),
        Order::factory()->failed()->create(),
        Order::factory()->processing()->withReservedKey()->create(),
        Order::factory()->withReservedKey()->create(),
    ])->not->toThrow(QueryException::class);
});
