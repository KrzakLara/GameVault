<?php

use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('never grants admin rights through registration', function () {
    $this->post('/register', [
        'name' => 'Mallory', 'email' => 'mallory@example.com',
        'password' => 'password', 'password_confirmation' => 'password', 'is_admin' => 1,
    ])->assertRedirect();

    expect(User::where('email', 'mallory@example.com')->sole()->is_admin)->toBeFalse();
});

it('never grants admin rights through a profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/settings/profile', ['name' => 'New Name', 'email' => $user->email, 'is_admin' => true])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->is_admin)->toBeFalse()->and($user->fresh()->name)->toBe('New Name');
});

it('ignores price, currency, status and foreign ids smuggled into a checkout', function () {
    Queue::fake(); // keep the order as checkout left it (no inline fulfillment)
    $buyer = User::factory()->create();
    $victim = User::factory()->create();
    $game = Game::factory()->create(['price_cents' => 4999]);
    $cheap = Game::factory()->create(['price_cents' => 1]);
    GameKey::factory()->for($game)->create();

    $this->actingAs($buyer)->postJson(route('orders.store', $game), [
        'idempotency_key' => (string) Str::uuid(),
        'price_cents' => 1, 'currency' => 'XXX', 'status' => 'delivered',
        'user_id' => $victim->id, 'game_id' => $cheap->id, 'is_admin' => true,
    ])->assertCreated();

    $order = Order::sole();
    expect($order->price_cents)->toBe(4999)
        ->and($order->currency)->toBe('EUR')
        ->and($order->status->value)->toBe('pending')
        ->and($order->user_id)->toBe($buyer->id)
        ->and($order->game_id)->toBe($game->id)
        ->and($buyer->fresh()->is_admin)->toBeFalse();
});

it('refuses to mass-assign guarded attributes outside production', function (string $model, array $attributes) {
    expect(fn () => $model::create($attributes))->toThrow(MassAssignmentException::class);
})->with([
    'order status' => [Order::class, ['status' => 'delivered']],
    'order failure code' => [Order::class, ['failure_code' => 'out_of_stock']],
    'order lease token' => [Order::class, ['lease_token' => 'forged']],
    'key status' => [GameKey::class, ['status' => 'sold']],
    'key order link' => [GameKey::class, ['order_id' => 1]],
    'user admin flag' => [User::class, ['is_admin' => true]],
]);

it('drops guarded attributes silently in production and never writes them', function () {
    Model::preventSilentlyDiscardingAttributes(false);
    $user = User::factory()->create();
    $game = Game::factory()->create();

    $order = Order::create(['user_id' => $user->id, 'game_id' => $game->id, 'price_cents' => 100, 'status' => 'delivered', 'lease_token' => 'forged']);
    $admin = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'is_admin' => true]);

    expect($order->fresh()->status->value)->toBe('pending')
        ->and($order->fresh()->lease_token)->toBeNull()
        ->and($admin->fresh()->is_admin)->toBeFalse();
    Model::preventSilentlyDiscardingAttributes(true);
});
