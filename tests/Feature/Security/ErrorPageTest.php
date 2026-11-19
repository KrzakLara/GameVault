<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

it('renders a branded error page with the right status for browser requests', function (Closure $request, int $status) {
    $request($this)->assertStatus($status)->assertInertia(fn (Assert $page) => $page
        ->component('Error')
        ->where('status', $status)
        ->has('requestId'));
})->with([
    'missing page' => [fn ($t) => $t->get('/no-such-page'), 404],
    'customer on an admin page' => [fn ($t) => $t->actingAs(User::factory()->create())->get('/admin'), 403],
]);

it('keeps JSON error responses as JSON', function () {
    $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message', 'request_id']);
});

it('hides 500 details behind the branded page when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('secret internal detail'));

    $response = $this->get('/_test/boom');

    $response->assertStatus(500)->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500));
    expect($response->getContent())->not->toContain('secret internal detail');
});
