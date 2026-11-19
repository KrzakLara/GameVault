<?php

namespace App\Models;

use App\Enums\KeyStatus;
use App\Support\KeyFingerprint;
use Database\Factories\GameKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property string $code Decrypted on access. Never serialised (see $hidden).
 * @property string|null $code_hash
 * @property KeyStatus $status
 * @property int|null $order_id
 * @property Carbon|null $reserved_at
 * @property Carbon|null $sold_at
 */
class GameKey extends Model
{
    /** @use HasFactory<GameKeyFactory> */
    use HasFactory;

    /**
     * Status and order linkage are intentionally NOT fillable: they only change through
     * InventoryAllocator, inside a locked transaction.
     */
    protected $fillable = ['game_id', 'code'];

    protected $hidden = ['code', 'code_hash'];

    protected $attributes = ['status' => 'available'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'status' => KeyStatus::class,
            'game_id' => 'integer',
            'order_id' => 'integer',
            'reserved_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (GameKey $key) {
            if ($key->isDirty('code')) {
                $key->code_hash = KeyFingerprint::of($key->code);
            }
        });
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
}
