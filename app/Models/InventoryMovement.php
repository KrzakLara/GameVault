<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $game_key_id
 * @property int|null $order_id
 * @property InventoryMovementType $type
 * @property int|null $actor_id
 * @property Carbon $created_at
 */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['game_id', 'game_key_id', 'order_id', 'type', 'actor_id'];

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
