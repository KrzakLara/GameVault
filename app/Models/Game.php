<?php

namespace App\Models;

use App\Enums\KeyStatus;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $platform
 * @property string $genre
 * @property int $price_cents
 * @property string $currency
 * @property string|null $cover_url
 * @property string|null $cheapshark_id
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_featured
 * @property int|null $stock
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    public const PLATFORMS = ['Steam', 'PlayStation', 'Xbox'];

    public const GENRES = ['Action', 'RPG', 'Strategy', 'Racing', 'Adventure', 'Shooter', 'Indie'];

    protected $fillable = [
        'name', 'slug', 'platform', 'genre', 'price_cents', 'currency', 'cover_url',
        'cheapshark_id', 'description', 'is_active', 'is_featured',
    ];

    /** Mirror the column defaults (see migration 000002). */
    protected $attributes = ['genre' => 'Action', 'currency' => 'EUR', 'is_active' => true, 'is_featured' => false];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /** @return HasMany<GameKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(GameKey::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Adds a `stock` count of available keys, served by the (game_id, status, id) index.
     *
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    public function scopeWithAvailableStock(Builder $query): Builder
    {
        return $query->withCount(['keys as stock' => fn ($q) => $q->where('status', KeyStatus::Available)]);
    }
}
