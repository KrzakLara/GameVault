<?php

namespace App\Actions\Checkout;

use App\Models\Order;

final readonly class CheckoutResult
{
    public function __construct(public Order $order, public bool $replayed) {}
}
