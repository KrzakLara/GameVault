<?php

use App\Enums\FailureCode;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;

beforeEach(function () {
    // Laravel skips CSRF verification while running tests. Enforce it, exactly as in production.
    $this->app->singleton(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    $this->admin = User::factory()->admin()->create();
    $this->delivered = Order::factory()->for($this->admin)->delivered('CSRF-TEST-KEY')->create();
    $this->failed = Order::factory()->failed(FailureCode::OutOfStock)->create();
    $this->game = Game::factory()->create();
    GameKey::factory()->for($this->game)->create();
    $this->dead = OutboxMessage::forceCreate(['event_id' => 'e-csrf', 'type' => 'order.delivered', 'aggregate_type' => 'Order', 'aggregate_id' => $this->delivered->id, 'payload' => ['order_id' => $this->delivered->id], 'status' => 'dead_letter', 'available_at' => now()]);
});

it('rejects every state-changing request without a CSRF token (419) and changes nothing', function (string $route) {
    $uri = match ($route) {
        'checkout' => route('orders.store', $this->game),
        'reveal' => route('orders.reveal', $this->delivered),
        'retry' => route('admin.orders.retry', $this->failed),
        'cancel' => route('admin.orders.cancel', $this->failed),
        'import' => route('admin.games.keys.store', $this->game),
        'requeue' => route('admin.outbox.requeue', $this->dead),
        'logout' => route('logout'),
    };

    $this->actingAs($this->admin)
        ->post($uri, ['idempotency_key' => (string) Str::uuid(), 'reason' => 'forged request', 'codes' => ['ABCDE-12345']])
        ->assertStatus(419);

    expect(Order::count())->toBe(2)
        ->and($this->failed->fresh()->status->value)->toBe('failed')
        ->and($this->game->keys()->count())->toBe(1)
        ->and($this->dead->fresh()->status->value)->toBe('dead_letter');
})->with(['checkout', 'reveal', 'retry', 'cancel', 'import', 'requeue', 'logout']);

it('accepts a form post carrying the session token', function () {
    $this->withSession(['_token' => 'csrf-test-token'])->actingAs($this->admin)
        ->post(route('orders.store', $this->game), ['_token' => 'csrf-test-token', 'idempotency_key' => (string) Str::uuid()])
        ->assertRedirect();

    expect(Order::count())->toBe(3);
});

it('accepts the encrypted X-XSRF-TOKEN header that axios sends for the reveal call', function () {
    $encrypter = app('encrypter');
    $header = $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).'csrf-test-token', false);

    $this->withSession(['_token' => 'csrf-test-token'])->actingAs($this->admin)
        ->withHeader('X-XSRF-TOKEN', $header)
        ->postJson(route('orders.reveal', $this->delivered))
        ->assertOk()->assertJsonPath('key', 'CSRF-TEST-KEY');
});
