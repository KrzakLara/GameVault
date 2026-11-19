<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public catalogue view of a game. Exposes the stock COUNT only, never any key.
 *
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'platform' => $this->platform,
            'genre' => $this->genre,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'cover_url' => $this->cover_url,
            'description' => $this->description,
            'is_featured' => $this->is_featured,
            'stock' => $this->when(isset($this->stock), fn () => (int) $this->stock),
        ];
    }
}
