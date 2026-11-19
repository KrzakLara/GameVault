<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KeyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportGameKeysRequest;
use App\Http\Resources\Admin\InventoryGameResource;
use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Stock levels per game. Key codes are never loaded here, only counts. */
class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $threshold = (int) config('gamevault.low_stock_threshold');
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $stock = in_array($request->query('stock'), ['low', 'out'], true) ? (string) $request->query('stock') : '';

        $games = Game::query()
            ->withCount([
                'keys as available' => fn (Builder $q) => $q->where('status', KeyStatus::Available),
                'keys as reserved' => fn (Builder $q) => $q->where('status', KeyStatus::Reserved),
                'keys as sold' => fn (Builder $q) => $q->where('status', KeyStatus::Sold),
            ])
            ->when($search !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($stock === 'low', fn (Builder $q) => $q->having('available', '<', $threshold))
            ->when($stock === 'out', fn (Builder $q) => $q->having('available', '=', 0))
            ->orderBy('available')->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Inventory', [
            'games' => InventoryGameResource::collection($games),
            'filters' => ['search' => $search, 'stock' => $stock],
            'threshold' => $threshold,
            'maxImport' => ImportGameKeysRequest::MAX_KEYS,
        ]);
    }
}
