<?php

namespace App\Http\Resources\Admin;

use App\Models\GameKey;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator view of an order: diagnostics included, secrets excluded. Admins can see a key's
 * id and lifecycle, but never its code (only the owner can reveal it).
 *
 * @mixin Order
 */
class AdminOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code?->value,
            'retryable' => (bool) $this->failure_code?->isRetryable(),
            'attempts' => $this->attempts,
            'lease_expires_at' => $this->lease_expires_at?->toIso8601String(),
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email,
            ] : null),
            'game' => $this->whenLoaded('game', fn () => ['id' => $this->game->id, 'name' => $this->game->name, 'slug' => $this->game->slug]),
            'key' => $this->whenLoaded('key', fn () => $this->key instanceof GameKey ? [
                'id' => $this->key->id,
                'status' => $this->key->status->value,
                'reserved_at' => $this->key->reserved_at?->toIso8601String(),
                'sold_at' => $this->key->sold_at?->toIso8601String(),
            ] : null),
            'created_at' => $this->created_at->toIso8601String(),
            'processing_started_at' => $this->processing_started_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
