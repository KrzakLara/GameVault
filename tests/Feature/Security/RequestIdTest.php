<?php

use App\Enums\FailureCode;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

it('assigns a ULID request id to every response, error responses included', function () {
    expect($this->get(route('games.index'))->headers->get('X-Request-Id'))->toMatch(ULID)
        ->and($this->get('/no-such-page')->assertNotFound()->headers->get('X-Request-Id'))->toMatch(ULID);
});

it('propagates a well-formed upstream request id', function () {
    $this->withHeader('X-Request-Id', 'lb-7f3a9c2e.req:42')->get(route('games.index'))
        ->assertHeader('X-Request-Id', 'lb-7f3a9c2e.req:42');
});

it('replaces a malformed or hostile upstream request id', function (string $incoming) {
    $id = $this->withHeader('X-Request-Id', $incoming)->get(route('games.index'))->headers->get('X-Request-Id');

    expect($id)->not->toBe($incoming)->toMatch(ULID);
})->with([
    'markup' => '<script>alert(1)</script>',
    'too short' => 'abc',
    'too long' => str_repeat('a', 200),
    'log injection' => 'abcdefgh%0Ainjected=1',
    'spaces' => 'abcdefgh ijklmnop',
]);

it('adds the request id to every log record and carries it into queued jobs', function () {
    config(['queue.default' => 'database']);
    $handler = new TestHandler;
    Log::channel()->getLogger()->pushHandler($handler);
    $game = Game::factory()->create();
    GameKey::factory()->for($game)->create();

    $this->actingAs(User::factory()->create())->withHeader('X-Request-Id', 'trace-0123456789')
        ->postJson(route('orders.store', $game), ['idempotency_key' => (string) Str::uuid()])->assertCreated();

    $accepted = collect($handler->getRecords())->first(fn ($r) => $r->message === 'checkout.accepted');
    expect($accepted)->not->toBeNull()
        ->and($accepted->extra['request_id'] ?? $accepted->context['request_id'] ?? null)->toBe('trace-0123456789')
        // The fulfillment job carries the originating request id to the worker.
        ->and(DB::table('jobs')->where('queue', 'fulfillment')->sole()->payload)->toContain('trace-0123456789');
});

it('stores the request id and client ip with audit entries', function () {
    $order = Order::factory()->failed(FailureCode::OutOfStock)->create();
    GameKey::factory()->for($order->game)->create();

    $this->actingAs(User::factory()->admin()->create())->withHeader('X-Request-Id', 'audit-trace-12345')
        ->postJson(route('admin.orders.retry', $order))->assertStatus(202);

    $entry = AuditLog::where('action', 'order.retry')->sole();
    expect($entry->request_id)->toBe('audit-trace-12345')
        ->and($entry->ip)->toBe('127.0.0.1');
});
