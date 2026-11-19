<?php

namespace App\Http\Resources\Admin;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Game
 *
 * @property int $available
 * @property int $reserved
 * @property int $sold
 */
class InventoryGameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'platform' => $this->platform,
            'is_active' => $this->is_active,
            'available' => (int) $this->available,
            'reserved' => (int) $this->reserved,
            'sold' => (int) $this->sold,
        ];
    }
}
