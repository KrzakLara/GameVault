<?php

namespace App\Models;

use App\Enums\FailureCode;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property int $game_id
 * @property int $price_cents
 * @property string $currency
 * @property string|null $idempotency_key
 * @property string|null $request_fingerprint
 * @property OrderStatus $status
 * @property FailureCode|null $failure_code
 * @property int $attempts Claims in the current fulfillment cycle (reset by an admin retry).
 * @property string|null $lease_token Fencing token of the worker that owns fulfillment.
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Game $game
 * @property-read User|null $user
 * @property-read GameKey|null $key
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Status, failure code, lease and lifecycle timestamps are not fillable: they only change via
     * transitionTo() and the fulfillment actions, never from request input.
     */
    protected $fillable = ['user_id', 'game_id', 'price_cents', 'currency', 'idempotency_key', 'request_fingerprint'];

    protected $hidden = ['idempotency_key', 'request_fingerprint', 'lease_token'];

    protected $attributes = ['status' => 'pending', 'currency' => 'EUR', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'failure_code' => FailureCode::class,
            'user_id' => 'integer',
            'game_id' => 'integer',
            'price_cents' => 'integer',
            'attempts' => 'integer',
            'lease_expires_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->public_id ??= (string) Str::ulid();
        });
    }

    /** URLs use the non-enumerable ULID, not the auto-increment id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return HasOne<GameKey, $this> */
    public function key(): HasOne
    {
        return $this->hasOne(GameKey::class);
    }

    /** @return HasMany<OrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /** True while some worker owns fulfillment and its lease has not run out. */
    public function hasLiveLease(?CarbonInterface $at = null): bool
    {
        return $this->status === OrderStatus::Processing
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->isAfter($at ?? now());
    }

    /**
     * Validated state transition. Callers must hold a row lock on this order (lockForUpdate)
     * inside a transaction; the timeline event is written in that same transaction.
     *
     * Leaving "processing" always clears the lease in the same UPDATE, which the
     * orders_lease_consistent CHECK constraint requires.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $attributes  Extra columns written in the same UPDATE.
     */
    public function transitionTo(
        OrderStatus $to,
        ?FailureCode $failure = null,
        ?int $actorId = null,
        array $meta = [],
        array $attributes = [],
    ): void {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidOrderTransition::between($this, $from, $to);
        }
        if ($to === OrderStatus::Failed && $failure === null) {
            throw new LogicException('A failed order must carry a failure code.');
        }

        $now = now();
        $this->status = $to;

        match ($to) {
            OrderStatus::Processing => $this->forceFill(['processing_started_at' => $now]),
            OrderStatus::Delivered => $this->forceFill(['delivered_at' => $now, 'failure_code' => null]),
            OrderStatus::Failed => $this->forceFill(['failed_at' => $now, 'failure_code' => $failure]),
            OrderStatus::Cancelled => $this->forceFill(['cancelled_at' => $now]),
            OrderStatus::Pending => $this->forceFill(['failure_code' => null, 'failed_at' => null]),
        };

        if ($to !== OrderStatus::Processing) {
            $this->forceFill(['lease_token' => null, 'lease_expires_at' => null]);
        }

        $this->forceFill($attributes)->save();

        $meta = array_filter(['failure_code' => $failure?->value, ...$meta], fn ($v) => $v !== null);

        $this->events()->create([
            'type' => 'status_changed',
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_id' => $actorId,
            'meta' => $meta ?: null,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    public function recordEvent(string $type, ?int $actorId = null, array $meta = []): void
    {
        $this->events()->create(['type' => $type, 'actor_id' => $actorId, 'meta' => $meta ?: null]);
    }
}
