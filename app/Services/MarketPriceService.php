<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketPriceService
{
    public function forGame(Game $game): ?array
    {
        if (! $game->cheapshark_id) return null;
        return Cache::remember('market-price:'.$game->cheapshark_id, now()->addMinutes(30), function () use ($game): ?array {
            try {
                $response = Http::timeout(4)->retry(2, 150)->get('https://www.cheapshark.com/api/1.0/games', [
                    'id' => $game->cheapshark_id,
                ]);
                if (! $response->successful()) return null;
                $data = $response->json();
                $deals = $data['deals'] ?? [];
                if (! is_array($deals) || count($deals) === 0) return null;
                $prices = array_filter(array_map(fn ($deal) => isset($deal['price']) && is_numeric($deal['price']) ? (float) $deal['price'] : null, $deals), fn ($v) => $v !== null);
                if (! $prices) return null;
                return ['lowest_usd' => min($prices), 'source' => 'CheapShark', 'currency' => 'USD'];
            } catch (\Throwable) { return null; } // noncritical external service
        });
    }
}
