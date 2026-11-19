<?php

use App\Enums\KeyStatus;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use Inertia\Testing\AssertableInertia as Assert;

it('lists only active games, with their available-stock count and no key data', function () {
    $game = Game::factory()->create(['name' => 'Alpha Quest']);
    GameKey::factory()->count(3)->for($game)->create();
    GameKey::factory()->for($game)->create()->forceFill(['status' => KeyStatus::Reserved, 'order_id' => Order::factory()->for($game)->create()->id, 'reserved_at' => now()])->save();
    Game::factory()->inactive()->create(['name' => 'Hidden Game']);

    $this->get(route('games.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('games/Index')
        ->has('games.data', 1)
        ->where('games.data.0.name', 'Alpha Quest')
        ->where('games.data.0.stock', 3)
        ->missing('games.data.0.id')
        ->missing('games.data.0.keys'));
});

it('filters by search, platform and genre, treats LIKE wildcards literally, and ignores unknown filter values', function () {
    Game::factory()->create(['name' => 'Neon 100% Drift', 'platform' => 'Steam', 'genre' => 'Racing']);
    Game::factory()->create(['name' => 'Neon Arena', 'platform' => 'Xbox', 'genre' => 'Shooter']);

    $names = fn (array $query) => collect($this->get(route('games.index', $query))->viewData('page')['props']['games']['data'])->pluck('name')->all();

    expect($names(['search' => 'neon']))->toHaveCount(2)
        ->and($names(['search' => '100%']))->toBe(['Neon 100% Drift'])
        ->and($names(['search' => '%']))->toBe(['Neon 100% Drift']) // literal %, not "match everything"
        ->and($names(['platform' => 'Xbox']))->toBe(['Neon Arena'])
        ->and($names(['genre' => 'Racing']))->toBe(['Neon 100% Drift'])
        ->and($names(['platform' => "Steam' OR 1=1 --"]))->toHaveCount(2); // unknown value: filter ignored
});

it('returns 404 for an inactive game', function () {
    $game = Game::factory()->inactive()->create();

    $this->get(route('games.show', $game))->assertNotFound();
});

it('renders a game at once and defers the external market lookup to a follow-up request', function () {
    $game = Game::factory()->create(['cheapshark_id' => '123']);
    GameKey::factory()->count(2)->for($game)->create();

    $this->get(route('games.show', $game))->assertInertia(fn (Assert $page) => $page
        ->component('games/Show')
        ->where('game.slug', $game->slug)
        ->where('game.stock', 2)
        ->missing('marketPrice') // never blocks the first render
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('marketPrice.status', 'unavailable')
            ->where('marketPrice.reason', 'disabled')));
});
