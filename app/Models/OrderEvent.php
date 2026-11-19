<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only fulfillment timeline entry.
 *
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $actor_id
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['type', 'from_status', 'to_status', 'actor_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
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
