<?php

use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use App\Services\MarketPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates a pending order and queues delivery', function () {
    Queue::fake();
    $user=User::factory()->create();$game=Game::factory()->create();
    $this->actingAs($user)->post(route('orders.store',$game))->assertRedirect(route('orders.index'));
    $order=Order::sole();expect($order->status)->toBe('pending');
    Queue::assertPushed(DeliverGameKey::class, fn ($job) => $job->orderId===$order->id);
});

it('delivers an available key and remains idempotent on retry', function () {
    $order=Order::factory()->create();$key=GameKey::factory()->create(['game_id'=>$order->game_id]);
    $job=new DeliverGameKey($order->id);$job->handle();$job->handle();
    expect($order->fresh()->status)->toBe('delivered');
    expect($key->fresh()->order_id)->toBe($order->id);
});

it('fails gracefully when inventory is empty', function () {
    $order=Order::factory()->create();
    (new DeliverGameKey($order->id))->handle();
    expect($order->fresh()->status)->toBe('failed');
});

it('never assigns the same key to two sequential orders', function () {
    $game=Game::factory()->create();$key=GameKey::factory()->create(['game_id'=>$game->id]);
    $first=Order::factory()->create(['game_id'=>$game->id]);
    $second=Order::factory()->create(['game_id'=>$game->id]);
    (new DeliverGameKey($first->id))->handle();(new DeliverGameKey($second->id))->handle();
    expect($first->fresh()->status)->toBe('delivered');
    expect($second->fresh()->status)->toBe('failed');
    expect($key->fresh()->order_id)->toBe($first->id);
});

it('forbids revealing another users key', function () {
    $owner=User::factory()->create();$stranger=User::factory()->create();
    $order=Order::factory()->create(['user_id'=>$owner->id,'status'=>'delivered']);
    GameKey::factory()->create(['game_id'=>$order->game_id,'status'=>'sold','order_id'=>$order->id,'code'=>'SECRET-KEY']);
    $this->actingAs($stranger)->get(route('orders.reveal',$order))->assertForbidden();
    $this->actingAs($owner)->get(route('orders.reveal',$order))->assertOk()->assertJsonPath('code','SECRET-KEY');
});

it('encrypts stored keys at rest', function () {
    $key=GameKey::factory()->create(['code'=>'NEVER-PLAIN-TEXT']);
    expect($key->getRawOriginal('code'))->not->toContain('NEVER-PLAIN-TEXT');
    expect($key->fresh()->code)->toBe('NEVER-PLAIN-TEXT');
});

it('caches external market prices', function () {
    Http::fake(['cheapshark.com/*'=>Http::response(['deals'=>[['price'=>'12.99'],['price'=>'17.50']]],200)]);
    $game=Game::factory()->create(['cheapshark_id'=>'1234']);
    $service=app(MarketPriceService::class);
    expect($service->forGame($game)['lowest_usd'])->toBe(12.99);
    expect($service->forGame($game)['lowest_usd'])->toBe(12.99);
    Http::assertSentCount(1);
});
