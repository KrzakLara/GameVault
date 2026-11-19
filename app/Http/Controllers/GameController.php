<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\MarketPriceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search',''));
        $platform = (string) $request->query('platform','');
        $games = Game::query()->withCount(['keys as stock' => fn ($q) => $q->where('status', 'available')])
            ->when($search !== '', fn ($q) => $q->where('name','like','%'.addcslashes($search,'%_\\').'%'))
            ->when(in_array($platform,['Steam','PlayStation','Xbox'],true), fn ($q) => $q->where('platform',$platform))
            ->orderBy('name')->paginate(12)->withQueryString();
        return Inertia::render('games/Index', [
            'games' => $games,
            'filters' => ['search' => $search, 'platform' => $platform],
        ]);
    }
    public function show(Game $game, MarketPriceService $market): Response
    {
        return Inertia::render('games/Show', [
            'game' => $game->loadCount(['keys as stock' => fn ($q) => $q->where('status','available')]),
            'marketPrice' => $market->forGame($game),
        ]);
    }
}
