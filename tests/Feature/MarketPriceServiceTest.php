<?php

use App\Models\Game;
use App\Services\MarketPriceService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['gamevault.cheapshark.enabled' => true, 'gamevault.cheapshark.retry_sleep_ms' => 0]);
    $this->freezeSecond();
});

function market(): MarketPriceService
{
    return app(MarketPriceService::class);
}

function pcGame(string $cheapsharkId = '1234'): Game
{
    return Game::factory()->create(['platform' => 'Steam', 'cheapshark_id' => $cheapsharkId]);
}

it('reports the lowest PC store offer with source, currency, scope and fetch time, and caches it', function () {
    Http::fake(['cheapshark.com/*' => Http::response(['deals' => [
        ['storeID' => '1', 'price' => '17.50', 'retailPrice' => '29.99'],
        ['storeID' => '2', 'price' => '12.99', 'retailPrice' => '29.99'],
    ]])]);
    $game = pcGame();

    $first = market()->forGame($game);
    $second = market()->forGame($game);

    expect($first)->toBe([
        'status' => 'available',
        'lowest' => 12.99,
        'retail' => 29.99,
        'savings_percent' => 57,
        'deal_count' => 2,
        'currency' => 'USD',
        'source' => 'CheapShark',
        'scope' => 'PC digital storefronts',
        'fetched_at' => now()->toIso8601String(),
    ])->and($second)->toBe($first);
    Http::assertSentCount(1);
});

it('never compares console titles with PC market data (and never calls the API for them)', function (string $platform) {
    Http::fake();

    $result = market()->forGame(Game::factory()->create(['platform' => $platform, 'cheapshark_id' => '1234']));

    expect($result['status'])->toBe('not_applicable')->and($result['message'])->toContain('PC storefronts only');
    Http::assertNothingSent();
})->with(['PlayStation', 'Xbox']);

it('reports untracked titles and a disabled integration without calling the API', function () {
    Http::fake();

    expect(market()->forGame(Game::factory()->create(['platform' => 'Steam', 'cheapshark_id' => null]))['reason'])->toBe('not_tracked');
    config(['gamevault.cheapshark.enabled' => false]);
    expect(market()->forGame(pcGame())['reason'])->toBe('disabled');
    Http::assertNothingSent();
});

it('retries a server error once, then caches the failure instead of calling the API on every view', function () {
    Http::fake(['cheapshark.com/*' => Http::response('upstream down', 502)]);
    $game = pcGame();

    expect(market()->forGame($game)['reason'])->toBe('temporarily_unavailable');
    Http::assertSentCount(2); // the request plus one bounded retry

    expect(market()->forGame($game)['reason'])->toBe('temporarily_unavailable');
    Http::assertSentCount(2); // served from the negative cache

    $this->travel((int) config('gamevault.cheapshark.failure_ttl_minutes') + 1)->minutes();
    market()->forGame($game);
    Http::assertSentCount(4); // negative cache expired: tried again
});

it('honours a 429 by pausing every lookup, not just this game, and never retries it', function () {
    Http::fake(['cheapshark.com/*' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '120'])
        ->push(['deals' => [['price' => '9.99']]])]);

    expect(market()->forGame(pcGame('1'))['reason'])->toBe('rate_limited');
    Http::assertSentCount(1); // not retried

    expect(market()->forGame(pcGame('2'))['reason'])->toBe('rate_limited'); // a different game: paused too
    Http::assertSentCount(1);

    $this->travel(121)->seconds(); // Retry-After elapsed
    expect(market()->forGame(pcGame('3'))['status'])->toBe('available');
    Http::assertSentCount(2);
});

it('treats malformed payloads and junk prices as unavailable instead of failing the page', function (mixed $body, string $reason) {
    Http::fake(['cheapshark.com/*' => Http::response($body)]);

    expect(market()->forGame(pcGame()))->toMatchArray(['status' => 'unavailable', 'reason' => $reason]);
})->with([
    'HTML instead of JSON' => ['<html>maintenance</html>', 'invalid_response'],
    'deals is not a list' => [['deals' => 'none'], 'invalid_response'],
    'no deals at all' => [['deals' => []], 'no_deals'],
    'non-numeric or negative prices' => [['deals' => [['price' => 'free'], ['price' => '-1'], ['nope' => 1]]], 'no_deals'],
]);

it('does not retry a client error such as an unknown game id', function () {
    Http::fake(['cheapshark.com/*' => Http::response(['error' => 'not found'], 404)]);

    expect(market()->forGame(pcGame())['reason'])->toBe('temporarily_unavailable');
    Http::assertSentCount(1);
});

it('degrades gracefully when the API cannot be reached at all', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(market()->forGame(pcGame()))->toMatchArray(['status' => 'unavailable', 'reason' => 'temporarily_unavailable']);
});
