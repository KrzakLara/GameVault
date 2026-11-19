<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use App\Models\Order;
use LogicException;

final class InvalidOrderTransition extends LogicException
{
    public static function between(Order $order, OrderStatus $from, OrderStatus $to): self
    {
        return new self("Order {$order->id} cannot transition from {$from->value} to {$to->value}.");
    }
}
