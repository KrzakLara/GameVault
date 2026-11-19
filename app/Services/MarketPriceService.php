<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional market reference price from CheapShark (USD). Non-critical: any failure degrades to
 * "no reference price" and is cached briefly, so an outage costs one request per game per
 * failure_ttl instead of one per page view.
 */
class MarketPriceService
{
    /** @return array{lowest_usd: float, source: string, currency: string}|null */
    public function forGame(Game $game): ?array
    {
        if (! config('gamevault.cheapshark.enabled') || ! $game->cheapshark_id) {
            return null;
        }

        $cacheKey = 'market-price:'.$game->cheapshark_id;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === false ? null : $cached; // false = cached failure
        }

        $result = $this->fetch($game->cheapshark_id);
        $ttl = (int) config($result ? 'gamevault.cheapshark.ttl_minutes' : 'gamevault.cheapshark.failure_ttl_minutes');
        Cache::put($cacheKey, $result ?? false, now()->addMinutes($ttl));

        return $result;
    }

    /** @return array{lowest_usd: float, source: string, currency: string}|null */
    private function fetch(string $cheapsharkId): ?array
    {
        try {
            $response = Http::timeout((int) config('gamevault.cheapshark.timeout_seconds'))
                ->retry((int) config('gamevault.cheapshark.retries') + 1, (int) config('gamevault.cheapshark.retry_sleep_ms'), throw: false)
                ->get(rtrim((string) config('gamevault.cheapshark.base_url'), '/').'/games', ['id' => $cheapsharkId]);

            if (! $response->successful()) {
                return null;
            }

            $deals = $response->json('deals');
            if (! is_array($deals)) {
                return null;
            }

            $prices = array_values(array_filter(
                array_map(fn ($deal) => is_array($deal) && is_numeric($deal['price'] ?? null) ? (float) $deal['price'] : null, $deals),
                fn ($price) => $price !== null && $price >= 0,
            ));

            return $prices === [] ? null : ['lowest_usd' => min($prices), 'source' => 'CheapShark', 'currency' => 'USD'];
        } catch (Throwable) {
            return null; // non-critical external service
        }
    }
}
