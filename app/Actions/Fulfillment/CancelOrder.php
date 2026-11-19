<?php

namespace App\Actions\Fulfillment;

use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\GameKey;
use App\Models\Order;
use App\Models\User;
use App\Support\Transactions;
use Illuminate\Validation\ValidationException;

/**
 * Admin closes a failed order permanently (e.g. a quarantined key_integrity failure).
 * Destructive: the request requires a written reason and the action is audited.
 */
final class CancelOrder
{
    public function handle(Order $order, User $admin, string $reason): void
    {
        Transactions::retrying(function () use ($order, $admin, $reason) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw ValidationException::withMessages(['order' => 'Only failed orders can be cancelled.']);
            }

            $locked->transitionTo(OrderStatus::Cancelled, actorId: $admin->id, meta: ['reason' => $reason]);

            // A quarantined (broken) key stays reserved against the cancelled order: it never
            // returns to sale.
            $quarantined = GameKey::query()->where('order_id', $locked->id)->where('status', KeyStatus::Reserved)->exists();
            AuditLog::record('order.cancel', $admin, $locked, ['reason' => $reason, 'quarantined_key_retained' => $quarantined]);
        });
    }
}
