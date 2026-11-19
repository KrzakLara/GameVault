<?php

namespace App\Http\Controllers;

use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontController extends Controller
{
    /** Trending window. The rule is shown to the visitor, so the ranking is never a mystery. */
    private const TRENDING_DAYS = 14;

    public function discover(): Response
    {
        $featured = Game::query()->active()->withAvailableStock()->where('is_featured', true)->orderBy('name')->limit(4)->get();

        // Real purchases only (failed and cancelled orders do not count).
        $purchases = Order::query()->toBase()
            ->where('created_at', '>=', now()->subDays(self::TRENDING_DAYS))
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value, OrderStatus::Delivered->value])
            ->select('game_id', DB::raw('COUNT(*) AS purchases'))
            ->groupBy('game_id')
            ->orderByDesc('purchases')
            ->limit(8)
            ->pluck('purchases', 'game_id');

        if ($purchases->isNotEmpty()) {
            $trending = Game::query()->active()->withAvailableStock()->whereKey($purchases->keys())->get()
                ->sortByDesc(fn (Game $game) => (int) $purchases[$game->id])->values();
            $rule = 'Most purchased in the last '.self::TRENDING_DAYS.' days';
        } else {
            $trending = Game::query()->active()->withAvailableStock()->latest('id')->limit(8)->get();
            $rule = 'Newest additions (no purchases in the last '.self::TRENDING_DAYS.' days yet)';
        }

        $perPlatform = Game::query()->active()->toBase()->select('platform', DB::raw('COUNT(*) AS games'))->groupBy('platform')->pluck('games', 'platform');

        return Inertia::render('Discover', [
            'featured' => GameResource::collection($featured)->resolve(),
            'trending' => ['rule' => $rule, 'games' => GameResource::collection($trending)->resolve()],
            'platforms' => collect(Game::PLATFORMS)->map(fn (string $p) => ['name' => $p, 'games' => (int) ($perPlatform[$p] ?? 0)])->all(),
            'stats' => [
                'games' => (int) $perPlatform->sum(),
                'keys_in_stock' => GameKey::query()->where('status', KeyStatus::Available)->whereHas('game', fn ($q) => $q->where('is_active', true))->count(),
            ],
        ]);
    }
}
