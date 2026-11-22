<?php

namespace App\Http\Controllers;

use App\Actions\Checkout\PlaceOrder;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /** The signed-in user's own orders only. */
    public function index(Request $request): Response
    {
        $orders = $this->currentUser($request)->orders()
            ->with('game:id,name,slug,platform,genre,cover_url')
            ->latest('id')
            ->paginate(10);

        return Inertia::render('orders/Index', ['orders' => OrderResource::collection($orders)]);
    }

    /** Detail page; also serves JSON for status polling while fulfillment runs. */
    public function show(Request $request, Order $order): Response|JsonResponse
    {
        Gate::authorize('view', $order);

        $order->load(['game', 'events']);

        if ($request->expectsJson()) {
            return OrderResource::make($order)->response()->header('Cache-Control', 'no-store, private');
        }

        return Inertia::render('orders/Show', ['order' => OrderResource::make($order)]);
    }

    /** Demo checkout ONLY: no payment is processed. */
    public function store(CheckoutRequest $request, Game $game, PlaceOrder $placeOrder): RedirectResponse|JsonResponse
    {
        $result = $placeOrder->handle($this->currentUser($request), $game, $request->idempotencyKey());
        $order = $result->order->load('game');

        if ($request->expectsJson()) {
            return OrderResource::make($order)->response()
                ->setStatusCode($result->replayed ? 200 : 201)
                ->header('Location', route('orders.show', $order))
                ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
        }

        return to_route('orders.show', $order)->with('success', $result->replayed
            ? 'This order was already placed.'
            : 'Demo order placed. Delivery is processing.');
    }

    /**
     * Returns the purchased key to its owner. POST (CSRF-protected, never cached or prefetched),
     * owner-only, rate limited, audited. The key is never written to logs or the audit trail.
     */
    public function reveal(Request $request, Order $order): JsonResponse
    {
        Gate::authorize('reveal', $order);

        if ($order->status !== OrderStatus::Delivered) {
            return response()->json(['message' => 'This order has not been delivered yet.', 'error' => 'not_delivered'], 409);
        }

        $key = $order->key()->where('status', KeyStatus::Sold)->firstOrFail();

        AuditLog::record('order.key_revealed', $this->currentUser($request), $order);

        return response()->json(['key' => $key->code])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
