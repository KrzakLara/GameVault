<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\Order;
use App\Services\Outbox\OutboxPublisher;
use Inertia\Inertia;
use Inertia\Response;

/** Operations overview. Every number is a live aggregate; nothing is sampled or invented. */
class DashboardController extends Controller
{
    public function __invoke(OutboxPublisher $outbox): Response
    {
        $orders = Order::query()->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $keys = GameKey::query()->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $threshold = (int) config('gamevault.low_stock_threshold');

        $lowStock = Game::query()->active()->withAvailableStock()
            ->having('stock', '<', $threshold)
            ->orderBy('stock')->orderBy('name')
            ->limit(8)->get()
            ->map(fn (Game $g) => ['slug' => $g->slug, 'name' => $g->name, 'platform' => $g->platform, 'stock' => (int) $g->stock]);

        return Inertia::render('admin/Dashboard', [
            'orders' => [
                'total' => $orders->sum(),
                'delivered' => $orders[OrderStatus::Delivered->value] ?? 0,
                'in_flight' => ($orders[OrderStatus::Pending->value] ?? 0) + ($orders[OrderStatus::Processing->value] ?? 0),
                'failed' => $orders[OrderStatus::Failed->value] ?? 0,
                'cancelled' => $orders[OrderStatus::Cancelled->value] ?? 0,
            ],
            'inventory' => [
                'available' => $keys['available'] ?? 0,
                'reserved' => $keys['reserved'] ?? 0,
                'sold' => $keys['sold'] ?? 0,
            ],
            'lowStock' => ['threshold' => $threshold, 'games' => $lowStock->all()],
            'outbox' => $outbox->stats(),
            'recentFailures' => AdminOrderResource::collection(
                Order::query()->with(['game:id,name,slug', 'user:id,name,email'])
                    ->where('status', OrderStatus::Failed)->latest('failed_at')->limit(5)->get()
            )->resolve(),
        ]);
    }
}
