<?php

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Game;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['app.debug' => false]); // production behaviour

    Route::middleware('web')->group(function () {
        Route::get('/_test/db-error', fn () => throw new QueryException('mysql', 'select * from orders where idempotency_key = ?', ['secret-idem-key'], new PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column')));
        Route::get('/_test/deadlock', fn () => throw new QueryException('mysql', 'update game_keys set status = ?', ['reserved'], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')));
        Route::get('/_test/transition', fn () => throw InvalidOrderTransition::between(Order::factory()->create(), OrderStatus::Delivered, OrderStatus::Pending));
        Route::get('/_test/runtime', fn () => throw new RuntimeException('internal detail at /app/secret/Path.php'));
    });
});

it('renders an unexpected database error as a generic 500 without SQL, bindings or traces', function () {
    $json = $this->getJson('/_test/db-error');

    $json->assertStatus(500)->assertExactJson(['message' => 'Server Error', 'request_id' => $json->headers->get('X-Request-Id')]);

    $html = $this->get('/_test/db-error')->assertStatus(500);
    foreach (['SQLSTATE', 'idempotency_key', 'secret-idem-key', 'select *', 'Stack trace', '/app/'] as $leak) {
        expect($json->getContent())->not->toContain($leak)->and($html->getContent())->not->toContain($leak);
    }
});

it('turns exhausted deadlock retries into a retryable 503', function () {
    $this->getJson('/_test/deadlock')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('error', 'temporarily_unavailable')
        ->assertJsonMissingPath('exception');
});

it('answers an invalid order state change with a generic 409 that names no internal ids', function () {
    $response = $this->getJson('/_test/transition');

    $response->assertStatus(409)->assertJsonPath('error', 'invalid_state');
    expect($response->getContent())->not->toMatch('/Order \d+/');
});

it('hides exception details from HTML error pages', function () {
    $content = $this->get('/_test/runtime')->assertStatus(500)->getContent();

    expect($content)->not->toContain('internal detail')->not->toContain('/app/secret');
});

it('gives framework errors the same JSON shape with the request id', function (Closure $request, int $status) {
    $response = $request($this);

    $response->assertStatus($status)->assertJsonStructure(['message', 'request_id']);
    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
})->with([
    'missing route' => [fn ($t) => $t->getJson('/definitely-not-a-route'), 404],
    'guest' => [fn ($t) => $t->getJson('/my-orders'), 401],
    'forbidden' => [fn ($t) => $t->actingAs(User::factory()->create())->getJson('/admin/orders'), 403],
    'validation' => [fn ($t) => $t->actingAs(User::factory()->create())->postJson('/games/'.Game::factory()->create()->slug.'/checkout', []), 422],
]);
