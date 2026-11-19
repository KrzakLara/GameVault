<?php

namespace App\Services\Outbox\Handlers;

use App\Models\Order;
use App\Models\OutboxMessage;
use App\Notifications\OrderDeliveredNotification;
use App\Services\Outbox\OutboxHandler;

final class OrderDeliveredHandler implements OutboxHandler
{
    public function handle(OutboxMessage $message): void
    {
        // Payloads are validated to scalars on write; cast so a malformed value can never turn this
        // into a multi-row find().
        $order = Order::query()->with(['user', 'game'])->find((int) ($message->payload['order_id'] ?? 0));

        // Account deleted since purchase: nothing to notify, and nothing to retry.
        if (! $order || ! $order->user) {
            return;
        }

        // Sent synchronously: we are already inside a queue worker, and a thrown exception must
        // reach the publisher so the message is retried instead of silently lost.
        $order->user->notifyNow(new OrderDeliveredNotification($order));
    }
}
