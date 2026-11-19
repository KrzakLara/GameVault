<?php

namespace App\Listeners;

use App\Events\OrderDelivered;
use Illuminate\Support\Facades\Mail;

class SendOrderDeliveredNotification
{
    public function handle(OrderDelivered $event): void
    {
        $order = $event->order->loadMissing(['user','game']);
        // No secret key in email: the user must authenticate to reveal it.
        Mail::raw("Your {$order->game->name} order #{$order->id} is ready. Sign in to GameVault to reveal your key.",
            fn ($message) => $message->to($order->user->email)->subject('Your GameVault order is ready'));
    }
}
