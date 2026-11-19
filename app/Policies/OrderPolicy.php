<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Customer-facing order access is owner-only. A non-owner gets 404, not 403, so the endpoint
 * does not even confirm that the order exists (order URLs use non-enumerable ULIDs as well).
 *
 * Administrators manage orders through the separate /admin area (can:admin); they can retry and
 * cancel, but they can never reveal a customer's key.
 */
class OrderPolicy
{
    public function view(User $user, Order $order): Response
    {
        return $this->owns($user, $order) ? Response::allow() : Response::denyAsNotFound();
    }

    /** Whether the order is delivered is a business rule (409), checked by the controller. */
    public function reveal(User $user, Order $order): Response
    {
        return $this->owns($user, $order) ? Response::allow() : Response::denyAsNotFound();
    }

    public function retry(User $user, Order $order): bool
    {
        return $user->is_admin === true;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->is_admin === true;
    }

    private function owns(User $user, Order $order): bool
    {
        return $order->user_id !== null && $order->user_id === $user->id;
    }
}
