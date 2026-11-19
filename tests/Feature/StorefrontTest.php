<?php

use App\Enums\FailureCode;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

describe('discover page', function () {
    it('shows featured games, real platform counts and real stock, from active games only', function () {
        $featured = Game::factory()->create(['name' => 'Alpha', 'platform' => 'Steam', 'is_featured' => true]);
        Game::factory()->create(['name' => 'Beta', 'platform' => 'Xbox']);
        Game::factory()->inactive()->create(['name' => 'Hidden', 'platform' => 'Xbox', 'is_featured' => true]);
        GameKey::factory()->count(3)->for($featured)->create();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Discover')
            ->has('featured', 1)
            ->where('featured.0.slug', $featured->slug)
            ->where('platforms', [['name' => 'Steam', 'games' => 1], ['name' => 'PlayStation', 'games' => 0], ['name' => 'Xbox', 'games' => 1]])
            ->where('stats', ['games' => 2, 'keys_in_stock' => 3]));
    });

    it('ranks trending games by real purchases in the last 14 days and says so', function () {
        [$hot, $warm, $cold] = Game::factory()->count(3)->create();
        Order::factory()->count(3)->for($hot)->create();
        Order::factory()->for($warm)->create();
        Order::factory()->for($cold)->failed(FailureCode::OutOfStock)->create(); // failures do not count
        Order::factory()->for($cold)->create(['created_at' => now()->subDays(20)]); // outside the window

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('trending.rule', 'Most purchased in the last 14 days')
            ->where('trending.games.0.slug', $hot->slug)
            ->where('trending.games.1.slug', $warm->slug)
            ->has('trending.games', 2));
    });

    it('labels the fallback ranking honestly when nobody bought anything recently', function () {
        Game::factory()->count(2)->create();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('trending.rule', 'Newest additions (no purchases in the last 14 days yet)')
            ->has('trending.games', 2));
    });
});

describe('catalogue', function () {
    beforeEach(function () {
        $this->cheap = Game::factory()->create(['name' => 'Cheap', 'price_cents' => 500]);
        $this->pricey = Game::factory()->create(['name' => 'Pricey', 'price_cents' => 6000, 'is_featured' => true]);
        $this->mid = Game::factory()->create(['name' => 'Middle', 'price_cents' => 2000]);
        GameKey::factory()->for($this->mid)->create();
    });

    $names = fn ($test, array $query) => collect($test->get(route('games.index', $query))->viewData('page')['props']['games']['data'])->pluck('name')->all();

    it('sorts on the server', function (string $sort, array $expected) use ($names) {
        expect($names($this, ['sort' => $sort]))->toBe($expected);
    })->with([
        'featured first' => ['featured', ['Pricey', 'Cheap', 'Middle']],
        'price ascending' => ['price_asc', ['Cheap', 'Middle', 'Pricey']],
        'price descending' => ['price_desc', ['Pricey', 'Middle', 'Cheap']],
        'name' => ['name', ['Cheap', 'Middle', 'Pricey']],
        'newest' => ['newest', ['Middle', 'Pricey', 'Cheap']],
    ]);

    it('filters to in-stock games and ignores unknown sort values', function () use ($names) {
        expect($names($this, ['in_stock' => 1]))->toBe(['Middle'])
            ->and($names($this, ['sort' => 'DROP TABLE']))->toBe(['Pricey', 'Cheap', 'Middle']);

        $this->get(route('games.index', ['sort' => 'nonsense']))->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'featured')
            ->where('sorts', ['featured', 'price_asc', 'price_desc', 'name', 'newest']));
    });

    it('paginates on the server, 12 per page, and keeps the filters in page links', function () {
        Game::factory()->count(13)->create(['platform' => 'Xbox']); // the 3 fixtures above are Steam

        $this->get(route('games.index', ['platform' => 'Xbox']))->assertInertia(fn (Assert $page) => $page
            ->has('games.data', 12)
            ->where('games.meta.total', 13)
            ->where('games.links.next', fn (string $url) => str_contains($url, 'platform=Xbox') && str_contains($url, 'page=2')));

        $this->get(route('games.index', ['platform' => 'Xbox', 'page' => 2]))->assertInertia(fn (Assert $page) => $page
            ->has('games.data', 1)
            ->where('games.meta.current_page', 2));
    });
});

it('shows related games for the same genre or platform, never the game itself or inactive ones', function () {
    $game = Game::factory()->create(['genre' => 'RPG', 'platform' => 'Steam']);
    $sameGenre = Game::factory()->create(['genre' => 'RPG', 'platform' => 'Xbox']);
    Game::factory()->create(['genre' => 'Racing', 'platform' => 'PlayStation']);
    Game::factory()->inactive()->create(['genre' => 'RPG']);

    $this->get(route('games.show', $game))->assertInertia(fn (Assert $page) => $page
        ->has('related', 1)
        ->where('related.0.slug', $sameGenre->slug)
        ->where('lowStockThreshold', (int) config('gamevault.low_stock_threshold')));
});

it('sends guests who want to buy through login and back to the game', function () {
    $game = Game::factory()->create();
    $user = User::factory()->create();

    $this->get(route('games.buy', $game))->assertRedirect(route('login'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('games.buy', $game));
    $this->get(route('games.buy', $game))->assertRedirect(route('games.show', $game));
});

it('shows the account dashboard with the signed-in user\'s own orders only', function () {
    $user = User::factory()->create();
    Order::factory()->for($user)->delivered()->create();
    Order::factory()->for($user)->failed()->create();
    Order::factory()->for($user)->create();
    Order::factory()->count(3)->create(); // somebody else's

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('stats', ['total' => 3, 'delivered' => 1, 'in_progress' => 1, 'unsuccessful' => 1])
        ->has('recentOrders', 3));
});
