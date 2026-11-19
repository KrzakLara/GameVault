<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Fulfillment\CancelOrder;
use App\Actions\Fulfillment\RetryFulfillment;
use App\Enums\FailureCode;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelOrderRequest;
use App\Http\Requests\Admin\RetryOrderRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator console for orders. Every route is behind auth + can:admin; mutations additionally
 * authorize in their Form Request and are audited by the actions they call.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $status = OrderStatus::tryFrom((string) $request->query('status'));
        $failure = FailureCode::tryFrom((string) $request->query('failure_code'));
        $search = preg_replace('/[^0-9A-Za-z]/', '', (string) $request->query('q', ''));

        $orders = Order::query()
            ->with(['game:id,name,slug', 'user:id,name,email', 'key'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($failure, fn ($q) => $q->where('failure_code', $failure))
            ->when($search !== '', fn ($q) => $q->where('public_id', 'like', strtoupper((string) $search).'%'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/orders/Index', [
            'orders' => AdminOrderResource::collection($orders),
            'filters' => ['status' => $status?->value, 'failure_code' => $failure?->value, 'q' => $search],
            'statuses' => OrderStatus::values(),
            'failureCodes' => array_column(FailureCode::cases(), 'value'),
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load(['game', 'user', 'key']);

        return Inertia::render('admin/orders/Show', [
            'order' => AdminOrderResource::make($order),
            'timeline' => $order->events()->with('actor:id,name')->orderBy('id')->get()
                ->map(fn (OrderEvent $e) => [
                    'type' => $e->type,
                    'from' => $e->from_status,
                    'to' => $e->to_status,
                    'actor' => $e->actor?->name,
                    'meta' => $e->meta,
                    'at' => $e->created_at->toIso8601String(),
                ]),
            'movements' => InventoryMovement::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (InventoryMovement $m) => ['type' => $m->type->value, 'key_id' => $m->game_key_id, 'at' => $m->created_at->toIso8601String()]),
            'audit' => AuditLog::query()->where('subject_type', 'Order')->where('subject_id', $order->id)
                ->with('actor:id,name')->latest('id')->limit(50)->get()
                ->map(fn (AuditLog $a) => ['action' => $a->action, 'actor' => $a->actor?->name, 'meta' => $a->meta, 'at' => $a->created_at->toIso8601String()]),
        ]);
    }

    public function retry(RetryOrderRequest $request, Order $order, RetryFulfillment $retry): RedirectResponse|JsonResponse
    {
        $retry->handle($order, $this->admin($request), $request->validated('note'));

        return $request->expectsJson()
            ? response()->json(['message' => 'Retry queued.', 'order' => AdminOrderResource::make($order->refresh())], 202)
            : back()->with('success', 'Retry queued.');
    }

    public function cancel(CancelOrderRequest $request, Order $order, CancelOrder $cancel): RedirectResponse|JsonResponse
    {
        $cancel->handle($order, $this->admin($request), (string) $request->validated('reason'));

        return $request->expectsJson()
            ? response()->json(['message' => 'Order cancelled.', 'order' => AdminOrderResource::make($order->refresh())])
            : back()->with('success', 'Order cancelled.');
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_admin, 403);

        return $user;
    }
}
