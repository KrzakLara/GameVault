<?php

namespace App\Http\Controllers;

use App\Enums\KeyStatus;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\MarketPriceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    /** Allowed sort keys; the frontend renders whatever this list contains. */
    public const SORTS = ['featured', 'price_asc', 'price_desc', 'name', 'newest'];

    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $platform = in_array($request->query('platform'), Game::PLATFORMS, true) ? (string) $request->query('platform') : '';
        $genre = in_array($request->query('genre'), Game::GENRES, true) ? (string) $request->query('genre') : '';
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'featured';
        $inStock = $request->boolean('in_stock');

        $games = Game::query()->active()->withAvailableStock()
            ->when($search !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($platform !== '', fn (Builder $q) => $q->where('platform', $platform))
            ->when($genre !== '', fn (Builder $q) => $q->where('genre', $genre))
            ->when($inStock, fn (Builder $q) => $q->whereHas('keys', fn (Builder $k) => $k->where('status', KeyStatus::Available)))
            ->tap(fn (Builder $q) => match ($sort) {
                'price_asc' => $q->orderBy('price_cents')->orderBy('name'),
                'price_desc' => $q->orderByDesc('price_cents')->orderBy('name'),
                'name' => $q->orderBy('name'),
                'newest' => $q->orderByDesc('id'),
                default => $q->orderByDesc('is_featured')->orderBy('name'),
            })
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('games/Index', [
            'games' => GameResource::collection($games),
            'filters' => ['search' => $search, 'platform' => $platform, 'genre' => $genre, 'sort' => $sort, 'in_stock' => $inStock],
            'platforms' => Game::PLATFORMS,
            'genres' => Game::GENRES,
            'sorts' => self::SORTS,
        ]);
    }

    public function show(Game $game, MarketPriceService $market): Response
    {
        abort_unless($game->is_active, 404);

        $related = Game::query()->active()->withAvailableStock()
            ->whereKeyNot($game->id)
            ->where(fn (Builder $q) => $q->where('genre', $game->genre)->orWhere('platform', $game->platform))
            ->orderByDesc('is_featured')->orderBy('name')
            ->limit(4)->get();

        return Inertia::render('games/Show', [
            'game' => GameResource::make(Game::query()->withAvailableStock()->findOrFail($game->id)),
            // Deferred: the page renders at once; a slow or failing external API never delays it.
            'marketPrice' => Inertia::defer(fn () => $market->forGame($game)),
            'related' => GameResource::collection($related)->resolve(),
            'lowStockThreshold' => (int) config('gamevault.low_stock_threshold'),
        ]);
    }

    /**
     * "Sign in to buy": guests are sent to the login page by the auth middleware, which remembers
     * this URL as the intended destination; after signing in they land back on the game.
     */
    public function buy(Game $game): RedirectResponse
    {
        return to_route('games.show', $game);
    }
}
