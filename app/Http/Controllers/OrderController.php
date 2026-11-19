<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::query()->where('user_id',$request->user()->id)
            ->with('game:id,name,slug,platform,cover_url')
            ->latest()->paginate(10);
        return Inertia::render('orders/Index', ['orders' => $orders]);
    }
    public function store(Request $request, Game $game): RedirectResponse
    {
        // Demo checkout ONLY: no actual payment is processed.
        $order = Order::create([
            'user_id' => $request->user()->id,
            'game_id' => $game->id,
            'price_cents' => $game->price_cents,
            'status' => 'pending',
        ]);
        DeliverGameKey::dispatch($order->id)->afterCommit();
        return to_route('orders.index')->with('success', 'Demo order placed. Delivery is processing.');
    }
    public function reveal(Request $request, Order $order): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('view', $order);
        abort_unless($order->status === 'delivered', 409, 'Order not delivered');
        $key = $order->key()->firstOrFail();
        return response()->json(['code' => $key->code])
            ->header('Cache-Control','no-store, private')
            ->header('Pragma','no-cache');
    }
}
