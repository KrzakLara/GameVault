<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional market reference price from CheapShark.
 *
 * CheapShark aggregates PC digital storefronts and reports USD, so a comparison is only offered
 * for PC (Steam) titles and is always labelled with its source, currency, scope and fetch time.
 * Console titles get an explicit "not applicable" state instead of a misleading number.
 *
 * Resilience: short timeouts, one retry only for connection errors and 5xx, negative caching of
 * failures, and a global pause after a 429 (honouring Retry-After) so a rate limit stops all
 * lookups instead of being hammered once per game.
 *
 * @phpstan-type MarketPriceResult array{status: 'available', lowest: float, retail: float|null, savings_percent: int|null, deal_count: int, currency: string, source: string, scope: string, fetched_at: string}|array{status: 'unavailable'|'not_applicable', reason: string, message: string}
 */
class MarketPriceService
{
    /** Platforms whose prices CheapShark's PC storefront data can be compared with. */
    public const PC_PLATFORMS = ['Steam'];

    private const PAUSE_KEY = 'market-price:paused';

    /**
     * @return MarketPriceResult
     */
    public function forGame(Game $game): array
    {
        if (! in_array($game->platform, self::PC_PLATFORMS, true)) {
            return self::state('not_applicable', 'platform_not_tracked', "CheapShark tracks PC storefronts only, so there is no comparable {$game->platform} market price.");
        }
        if (! config('gamevault.cheapshark.enabled')) {
            return self::state('unavailable', 'disabled', 'Market comparison is turned off.');
        }
        if (! $game->cheapshark_id) {
            return self::state('unavailable', 'not_tracked', 'This title has no external market listing.');
        }

        $cacheKey = 'market-price:v2:'.$game->cheapshark_id;
        $cached = Cache::get($cacheKey);
        if (self::isResult($cached)) {
            return $cached;
        }

        if (Cache::has(self::PAUSE_KEY)) {
            return self::state('unavailable', 'rate_limited', 'Market data is temporarily unavailable.');
        }

        [$result, $ttlSeconds] = $this->fetch((string) $game->cheapshark_id);
        Cache::put($cacheKey, $result, $ttlSeconds);

        return $result;
    }

    /**
     * @return array{0: MarketPriceResult, 1: int} The result and how long to cache it.
     */
    private function fetch(string $cheapsharkId): array
    {
        $failureTtl = 60 * (int) config('gamevault.cheapshark.failure_ttl_minutes');

        try {
            $response = Http::timeout((int) config('gamevault.cheapshark.timeout_seconds'))
                ->connectTimeout(2)
                ->acceptJson()
                ->withUserAgent('GameVault/1.0 (portfolio demo)')
                ->retry(
                    (int) config('gamevault.cheapshark.retries') + 1,
                    (int) config('gamevault.cheapshark.retry_sleep_ms'),
                    // Retry only what can succeed on a second try; never retry 4xx or a 429.
                    fn (Throwable $e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->get(rtrim((string) config('gamevault.cheapshark.base_url'), '/').'/games', ['id' => $cheapsharkId]);
        } catch (Throwable $e) {
            Log::warning('market_price.unreachable', ['exception' => class_basename($e)]);

            return [self::state('unavailable', 'temporarily_unavailable', 'Market data is temporarily unavailable.'), $failureTtl];
        }

        if ($response->status() === 429) {
            // Pause ALL lookups for as long as the provider asks (bounded), not just this game.
            $pause = min(3600, max(60, (int) $response->header('Retry-After')));
            Cache::put(self::PAUSE_KEY, true, $pause);
            Log::warning('market_price.rate_limited', ['pause_seconds' => $pause]);

            return [self::state('unavailable', 'rate_limited', 'Market data is temporarily unavailable.'), $pause];
        }

        if (! $response->successful()) {
            return [self::state('unavailable', 'temporarily_unavailable', 'Market data is temporarily unavailable.'), $failureTtl];
        }

        $deals = $response->json('deals');
        if (! is_array($deals)) {
            return [self::state('unavailable', 'invalid_response', 'Market data is temporarily unavailable.'), $failureTtl];
        }

        $prices = [];
        $retails = [];
        foreach ($deals as $deal) {
            if (is_array($deal) && is_numeric($deal['price'] ?? null) && (float) $deal['price'] >= 0) {
                $prices[] = (float) $deal['price'];
                if (is_numeric($deal['retailPrice'] ?? null) && (float) $deal['retailPrice'] > 0) {
                    $retails[] = (float) $deal['retailPrice'];
                }
            }
        }

        if ($prices === []) {
            return [self::state('unavailable', 'no_deals', 'No current PC store offers were found.'), $failureTtl];
        }

        $lowest = min($prices);
        $retail = $retails === [] ? null : max($retails);

        return [[
            'status' => 'available',
            'lowest' => round($lowest, 2),
            'retail' => $retail === null ? null : round($retail, 2),
            'savings_percent' => $retail ? (int) round((1 - $lowest / $retail) * 100) : null,
            'deal_count' => count($prices),
            'currency' => 'USD',
            'source' => 'CheapShark',
            'scope' => 'PC digital storefronts',
            'fetched_at' => now()->toIso8601String(),
        ], 60 * (int) config('gamevault.cheapshark.ttl_minutes')];
    }

    /**
     * Cache entries are written only by this service; the check guards against a foreign or
     * pre-v2 value under the same key.
     *
     * @phpstan-assert-if-true MarketPriceResult $value
     */
    private static function isResult(mixed $value): bool
    {
        return is_array($value) && in_array($value['status'] ?? null, ['available', 'unavailable', 'not_applicable'], true);
    }

    /** @return array{status: 'unavailable'|'not_applicable', reason: string, message: string} */
    private static function state(string $status, string $reason, string $message): array
    {
        /** @var 'unavailable'|'not_applicable' $status */
        return ['status' => $status, 'reason' => $reason, 'message' => $message];
    }
}
