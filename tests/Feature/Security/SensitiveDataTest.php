<?php

use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('never exposes key codes, fingerprints, idempotency keys or lease tokens on any page or API response', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();
    GameKey::factory()->for($game)->create(['code' => 'UNSOLD-SECRET-11111']);
    $delivered = Order::factory()->for($owner)->for($game)->delivered('DELIVERED-SECRET-22222')->create();
    $inFlight = Order::factory()->for($owner)->for($game)->processing()->withReservedKey('RESERVED-SECRET-33333')->create();

    $secrets = ['UNSOLD-SECRET-11111', 'DELIVERED-SECRET-22222', 'RESERVED-SECRET-33333',
        $delivered->idempotency_key, $inFlight->idempotency_key, $inFlight->lease_token, 'request_fingerprint',
        ...GameKey::pluck('code_hash')->all()];

    $responses = [
        $this->actingAs($owner)->get(route('games.index')),
        $this->actingAs($owner)->get(route('games.show', $game)),
        $this->actingAs($owner)->get(route('orders.index')),
        $this->actingAs($owner)->get(route('orders.show', $delivered)),
        $this->actingAs($owner)->get(route('orders.show', $inFlight)),
        $this->actingAs($owner)->getJson(route('orders.show', $inFlight)),
        $this->actingAs($admin)->get(route('admin.orders.index')),
        $this->actingAs($admin)->get(route('admin.orders.show', $delivered)),
        $this->actingAs($admin)->get(route('admin.orders.show', $inFlight)),
        $this->actingAs($admin)->get(route('admin.outbox.index')),
    ];

    foreach ($responses as $response) {
        $response->assertOk();
        foreach ($secrets as $secret) {
            expect($response->getContent())->not->toContain((string) $secret);
        }
    }
});

it('keeps the key out of logs, audit trail, timeline, outbox, queue and e-mail across a full purchase, while the owner can reveal it', function () {
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
        $logs[] = $e->message.' '.json_encode($e->context);
    });

    $buyer = User::factory()->create();
    $game = Game::factory()->create();
    GameKey::factory()->for($game)->create(['code' => 'FLOW-SECRET-ABCDE-12345']);

    // Checkout -> (sync queue) fulfillment -> outbox publisher -> e-mail -> reveal.
    $this->actingAs($buyer)->postJson(route('orders.store', $game), ['idempotency_key' => (string) Str::uuid()])->assertCreated();
    $order = Order::sole();
    expect($order->status->value)->toBe('delivered');

    $this->actingAs($buyer)->postJson(route('orders.reveal', $order))
        ->assertOk()->assertJsonPath('key', 'FLOW-SECRET-ABCDE-12345');

    $stored = collect(['audit_logs', 'order_events', 'outbox_messages', 'inventory_movements', 'orders', 'jobs', 'failed_jobs', 'game_keys'])
        ->mapWithKeys(fn (string $t) => [$t => json_encode(DB::table($t)->get())]);
    $mails = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage()->toString());

    expect($logs)->not->toBeEmpty()
        ->and(implode("\n", $logs))->not->toContain('FLOW-SECRET-ABCDE-12345')
        ->and($stored->filter(fn (string $dump) => str_contains($dump, 'FLOW-SECRET-ABCDE-12345'))->keys()->all())->toBe([])
        ->and($mails)->toHaveCount(1)
        ->and($mails->first())->not->toContain('FLOW-SECRET-ABCDE-12345')
        ->and($mails->first())->toContain($order->public_id);
});

it('returns a revealed key with no-store caching headers', function () {
    $order = Order::factory()->delivered()->create();

    $response = $this->actingAs($order->user)->postJson(route('orders.reveal', $order))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
});

it('refuses to reveal a key before the order is delivered', function () {
    $order = Order::factory()->withReservedKey('NOT-YET-DELIVERED-1')->create();

    $response = $this->actingAs($order->user)->postJson(route('orders.reveal', $order));

    $response->assertStatus(409)->assertJsonPath('error', 'not_delivered');
    expect($response->getContent())->not->toContain('NOT-YET-DELIVERED-1');
});

it('shares only a whitelisted user object with every page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('games.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user', fn ($shared) => array_keys(collect($shared)->all())
            === ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at', 'is_admin']));
});

it('hides secrets when models are serialised', function () {
    $order = Order::factory()->processing()->withReservedKey()->create();

    expect(array_keys($order->toArray()))->not->toContain('idempotency_key', 'request_fingerprint', 'lease_token')
        ->and(array_keys($order->key->toArray()))->not->toContain('code', 'code_hash')
        ->and(array_keys($order->user->toArray()))->not->toContain('password', 'remember_token');
});

it('never flashes submitted key codes back into the session after a failed import', function () {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();

    $this->actingAs($admin)->from(route('admin.orders.index'))
        ->post(route('admin.games.keys.store', $game), ['codes' => ['GOODKEY-12345', 'not a valid key!']])
        ->assertRedirect(route('admin.orders.index'))
        ->assertSessionHasErrors('codes.1');

    expect(session()->getOldInput())->not->toHaveKey('codes')
        ->and(json_encode(session()->all()))->not->toContain('GOODKEY-12345');
});
