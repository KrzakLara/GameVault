<?php

namespace App\Http\Resources\Admin;

use App\Models\OutboxMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Operator view of an outbox message. The payload is omitted (identifiers only anyway) and the
 * fencing token is never exposed.
 *
 * @mixin OutboxMessage
 */
class OutboxMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'type' => $this->type,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'available_at' => $this->available_at->toIso8601String(),
            'locked_until' => $this->locked_until?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'dead_lettered_at' => $this->dead_lettered_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
