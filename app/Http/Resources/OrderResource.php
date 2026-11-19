<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer view of an order. Identified by its ULID only. Never contains the key itself (that
 * is only returned by the audited, rate-limited reveal endpoint), the idempotency key, internal
 * ids, lease data or internal failure details.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $delivered = $this->status === OrderStatus::Delivered;

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'game' => $this->whenLoaded('game', fn () => [
                'name' => $this->game->name,
                'slug' => $this->game->slug,
                'platform' => $this->game->platform,
                'cover_url' => $this->game->cover_url,
            ]),
            'failure_message' => $this->status === OrderStatus::Failed ? $this->failure_code?->message() : null,
            'can_reveal' => $delivered,
            'created_at' => $this->created_at->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'timeline' => $this->whenLoaded('events', fn () => $this->events
                ->where('type', 'status_changed')
                ->map(fn (OrderEvent $e) => ['status' => $e->to_status, 'at' => $e->created_at->toIso8601String()])
                ->values()),
        ];
    }
}
