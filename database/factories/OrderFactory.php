<?php

namespace Database\Factories;

use App\Enums\FailureCode;
use App\Enums\InventoryMovementType;
use App\Enums\KeyStatus;
use App\Models\Game;
use App\Models\GameKey;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory states bypass the state machine on purpose (test fixtures); production code only
 * changes status through Order::transitionTo(). Every state still satisfies the database CHECK
 * constraints, which reject impossible combinations.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'game_id' => Game::factory(),
            'price_cents' => 1999,
            'currency' => 'EUR',
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** Pending order holding a reserved key, i.e. the state right after checkout. */
    public function withReservedKey(?string $code = null): static
    {
        return $this->afterCreating(function (Order $order) use ($code) {
            $key = GameKey::factory()->for($order->game)->create($code ? ['code' => $code] : []);
            $key->forceFill(['status' => KeyStatus::Reserved, 'order_id' => $order->id, 'reserved_at' => now()])->save();
            self::movement($key, $order, InventoryMovementType::Reserved);
        });
    }

    /** Claimed by a worker whose lease ends at $leaseExpiresAt (in the past = crashed worker). */
    public function processing(?DateTimeInterface $leaseExpiresAt = null): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
            'processing_started_at' => now(),
            'attempts' => 1,
            'lease_token' => (string) Str::ulid(),
            'lease_expires_at' => $leaseExpiresAt ?? now()->addMinute(),
        ]);
    }

    public function delivered(?string $code = null): static
    {
        return $this->state(['status' => 'delivered', 'delivered_at' => now()])
            ->afterCreating(function (Order $order) use ($code) {
                $key = GameKey::factory()->for($order->game)->create($code ? ['code' => $code] : []);
                $key->forceFill(['status' => KeyStatus::Sold, 'order_id' => $order->id, 'reserved_at' => now(), 'sold_at' => now()])->save();
                self::movement($key, $order, InventoryMovementType::Reserved);
                self::movement($key, $order, InventoryMovementType::Sold);
            });
    }

    public function failed(FailureCode $code = FailureCode::OutOfStock): static
    {
        return $this->state(['status' => 'failed', 'failure_code' => $code, 'failed_at' => now()]);
    }

    /** Keeps fixtures consistent with the inventory ledger, as InventoryAllocator would. */
    private static function movement(GameKey $key, Order $order, InventoryMovementType $type): void
    {
        InventoryMovement::create([
            'game_id' => $key->game_id, 'game_key_id' => $key->id, 'order_id' => $order->id, 'type' => $type,
        ]);
    }
}
