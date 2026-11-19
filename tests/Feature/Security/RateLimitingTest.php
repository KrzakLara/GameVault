<?php

use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    $this->game = Game::factory()->create();
    GameKey::factory()->count(10)->for($this->game)->create();
});

it('limits checkouts per user, answers 429 with Retry-After, and keeps budgets separate per user', function () {
    config(['gamevault.checkout.rate_limit_per_minute' => 3]);
    $user = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 3) as $_) {
        $this->actingAs($user)->postJson(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])->assertCreated();
    }

    $this->actingAs($user)->postJson(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'too_many_requests');

    $this->actingAs($other)->postJson(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])->assertCreated();

    $this->travel(61)->seconds();
    $this->actingAs($user)->postJson(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])->assertCreated();

    expect(Order::count())->toBe(5);
});

it('limits key reveals per user', function () {
    config(['gamevault.reveal.rate_limit_per_minute' => 2]);
    $order = Order::factory()->delivered()->create();

    $this->actingAs($order->user)->postJson(route('orders.reveal', $order))->assertOk();
    $this->actingAs($order->user)->postJson(route('orders.reveal', $order))->assertOk();
    $this->actingAs($order->user)->postJson(route('orders.reveal', $order))
        ->assertStatus(429)->assertJsonPath('error', 'too_many_requests')->assertJsonMissingPath('key');
});

it('throttles browser (Inertia) checkouts with a redirect that carries the error', function () {
    config(['gamevault.checkout.rate_limit_per_minute' => 1]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
    $this->actingAs($user)->from(route('games.show', $this->game))
        ->post(route('orders.store', $this->game), ['idempotency_key' => (string) Str::uuid()])
        ->assertRedirect(route('games.show', $this->game))
        ->assertSessionHasErrors('checkout');

    expect(Order::count())->toBe(1);
});
