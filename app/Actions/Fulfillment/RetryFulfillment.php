<?php

namespace App\Actions\Fulfillment;

use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Inventory\InventoryAllocator;
use App\Support\Transactions;
use Illuminate\Validation\ValidationException;

/**
 * Admin-initiated retry of a failed order. Re-reserves stock up front so the operator gets
 * immediate feedback, starts a new fulfillment cycle (fresh attempt budget), and is safe to
 * click twice: the second call sees an order that is no longer failed.
 */
final class RetryFulfillment
{
    public function __construct(private readonly InventoryAllocator $inventory) {}

    public function handle(Order $order, User $admin, ?string $note = null): void
    {
        Transactions::retrying(function () use ($order, $admin, $note) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Failed) {
                throw ValidationException::withMessages(['order' => 'Only failed orders can be retried.']);
            }
            if (! $locked->failure_code?->isRetryable()) {
                throw ValidationException::withMessages(['order' => 'This failure requires manual investigation and cannot be retried.']);
            }

            $previous = $locked->failure_code;
            $locked->transitionTo(OrderStatus::Pending, actorId: $admin->id, meta: ['reason' => 'admin_retry'], attributes: ['attempts' => 0]);

            if (! $this->inventory->reserve($locked, $admin->id)) {
                throw ValidationException::withMessages(['order' => 'No stock available to retry this order.']); // rolls back
            }

            AuditLog::record('order.retry', $admin, $locked, array_filter([
                'previous_failure' => $previous->value,
                'note' => $note,
            ]));

            DeliverGameKey::dispatch($locked->id)->afterCommit();
        });
    }
}
