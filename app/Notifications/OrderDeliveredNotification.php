<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Deliberately contains no key material: the user must sign in and reveal the key, which is
 * authorised, rate limited and audited.
 */
class OrderDeliveredNotification extends Notification
{
    public function __construct(public readonly Order $order) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->order->game->name} key is ready")
            ->greeting('Your order has been delivered.')
            ->line("Order {$this->order->public_id} for {$this->order->game->name} is ready.")
            ->line('For your security the key is not included in this email.')
            ->action('Reveal your key', route('orders.show', $this->order))
            ->line('GameVault is a portfolio demonstration. No real purchase took place.');
    }
}
