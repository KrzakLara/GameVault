<?php

use App\Models\Game;
use App\Providers\AppServiceProvider;
use App\Support\E2eGuard;
use Database\Seeders\E2eSeeder;
use Database\Seeders\LoadTestSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Playwright stack rebuilds its database on every run. These tests pin that it can only ever
 * do so on the isolated E2E database: the guard runs before anything destructive.
 */

it('refuses to reset outside the e2e environment and leaves the database untouched', function () {
    $game = Game::factory()->create();

    $this->artisan('e2e:reset')
        ->expectsOutputToContain('Refusing to use database "gamevault_test" in environment "testing"')
        ->assertFailed();

    expect(Schema::hasTable('games'))->toBeTrue()->and($game->fresh())->not->toBeNull();
});

it('refuses to reset in the e2e environment when the database is not an _e2e database', function () {
    $this->app['env'] = 'e2e';
    $game = Game::factory()->create();

    $this->artisan('e2e:reset')->expectsOutputToContain('requires APP_ENV=e2e and a database whose name ends in _e2e')->assertFailed();

    expect($game->fresh())->not->toBeNull();
});

it('refuses to seed E2E fixtures into a non-E2E database', function () {
    $this->app['env'] = 'e2e';

    expect(fn () => app()->call([new E2eSeeder, 'run']))->toThrow(RuntimeException::class, 'Refusing to use database "gamevault_test"');
    expect(Game::count())->toBe(0);
});

it('stops the app from booting in the e2e environment against a non-E2E database', function () {
    $this->app['env'] = 'e2e';

    expect(fn () => (new AppServiceProvider($this->app))->boot())->toThrow(RuntimeException::class, 'ends in _e2e');
});

it('accepts only the combination of the e2e environment and an _e2e database', function (string $env, string $database, bool $allowed) {
    $this->app['env'] = $env;
    config(['database.connections.'.config('database.default').'.database' => $database]);

    $allowed
        ? expect(fn () => E2eGuard::assertIsolated())->not->toThrow(RuntimeException::class)
        : expect(fn () => E2eGuard::assertIsolated())->toThrow(RuntimeException::class);
})->with([
    'e2e + gamevault_e2e' => ['e2e', 'gamevault_e2e', true],
    'e2e + dev database' => ['e2e', 'gamevault', false],
    'e2e + suffix trick' => ['e2e', 'gamevault_e2e_backup', false],
    'local + gamevault_e2e' => ['local', 'gamevault_e2e', false],
    'production + gamevault_e2e' => ['production', 'gamevault_e2e', false],
]);

it('keeps every live-stack command out of non-E2E databases', function (string $command, array $arguments) {
    $this->artisan($command, $arguments)->expectsOutputToContain('Refusing to use database "gamevault_test"')->assertFailed();
})->with([
    'live queue checks (places orders, simulates an outage)' => ['gamevault:verify-live', []],
    'key leak scan (decrypts every key)' => ['gamevault:scan-key-leaks', ['paths' => ['storage/logs']]],
    'load-test report' => ['gamevault:loadtest-report', []],
]);

it('refuses to seed load-test fixtures into a non-E2E database', function () {
    expect(fn () => app()->call([new LoadTestSeeder, 'run']))->toThrow(RuntimeException::class, 'ends in _e2e');
    expect(DB::table('users')->where('email', 'like', 'load%@e2e.test')->count())->toBe(0);
});
