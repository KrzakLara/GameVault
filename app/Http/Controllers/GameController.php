<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\MarketPriceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $platform = in_array($request->query('platform'), Game::PLATFORMS, true) ? (string) $request->query('platform') : '';
        $genre = in_array($request->query('genre'), Game::GENRES, true) ? (string) $request->query('genre') : '';

        $games = Game::query()->active()->withAvailableStock()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($platform !== '', fn ($q) => $q->where('platform', $platform))
            ->when($genre !== '', fn ($q) => $q->where('genre', $genre))
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(12)->withQueryString();

        return Inertia::render('games/Index', [
            'games' => GameResource::collection($games),
            'filters' => ['search' => $search, 'platform' => $platform, 'genre' => $genre],
            'platforms' => Game::PLATFORMS,
            'genres' => Game::GENRES,
        ]);
    }

    public function show(Game $game, MarketPriceService $market): Response
    {
        abort_unless($game->is_active, 404);

        return Inertia::render('games/Show', [
            'game' => GameResource::make(Game::query()->withAvailableStock()->findOrFail($game->id)),
            'marketPrice' => $market->forGame($game),
        ]);
    }
}
