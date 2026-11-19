<?php

namespace App\Actions\Fulfillment;

use App\Enums\FailureCode;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Jobs\PublishOutboxMessages;
use App\Models\GameKey;
use App\Models\Order;
use App\Services\Fulfillment\KeyProvisioner;
use App\Services\Inventory\InventoryAllocator;
use App\Services\Outbox\Outbox;
use App\Support\Transactions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Business-level idempotent fulfillment, safe under at-least-once queue delivery: any number of
 * repeated or concurrent executions for one order produce exactly one delivery, one sold key and
 * one outbox message.
 *
 * Three short steps, so no transaction is ever held open during slow or external work:
 *   1. claim     (txn)  lock order; take a LEASE with a fresh FENCING TOKEN; ensure a reserved key
 *   2. provision (none) key integrity check + external hand-off (KeyProvisioner)
 *   3. finalize  (txn)  only if the order still carries OUR token: key -> sold, order -> delivered,
 *                       outbox message, all in one commit
 *
 * Ownership rules:
 *  - While a lease is live, other executions do nothing (Busy): no concurrent provisioning.
 *  - An expired lease can be taken over (worker crashed or hung); the takeover issues a new
 *    token, so the old worker, if it was only slow, is fenced off at finalize (Superseded).
 *  - A worker whose lease expired but was NOT taken over still holds the current token and may
 *    finish: nobody else did any work.
 *  - On a transient error the worker relinquishes its lease so the queue retry can take over
 *    immediately; after a hard crash the lease simply runs out.
 */
final class FulfillOrder
{
    public function __construct(
        private readonly InventoryAllocator $inventory,
        private readonly Outbox $outbox,
        private readonly FailOrder $failOrder,
        private readonly KeyProvisioner $provisioner,
    ) {}

    public function handle(int $orderId): FulfillmentOutcome
    {
        $startedAt = hrtime(true);

        $claim = $this->claim($orderId);
        if ($claim instanceof FulfillmentOutcome) {
            return $claim;
        }

        try {
            if (! $this->keyIsIntact($claim)) {
                $this->failOrder->handle($orderId, FailureCode::KeyIntegrity, leaseToken: $claim->token);

                return FulfillmentOutcome::Failed;
            }

            $this->provisioner->provision($claim);

            $outcome = $this->finalize($claim);
        } catch (Throwable $e) {
            $this->relinquish($claim);

            throw $e;
        }

        Log::info('fulfillment.finished', [
            'order_id' => $orderId,
            'outcome' => $outcome->value,
            'attempt' => $claim->attempt,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1e6),
        ]);

        return $outcome;
    }

    /** Step 1. Returns a claim, or the reason there is nothing for this execution to do. */
    public function claim(int $orderId): FulfillmentClaim|FulfillmentOutcome
    {
        return Transactions::retrying(function () use ($orderId): FulfillmentClaim|FulfillmentOutcome {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if (! $order || ! $order->status->isInFlight()) {
                return FulfillmentOutcome::AlreadyFinal; // duplicate or late execution: no-op
            }

            if ($order->hasLiveLease()) {
                Log::info('fulfillment.busy', ['order_id' => $order->id]);

                return FulfillmentOutcome::Busy;
            }

            if ($order->attempts >= (int) config('gamevault.fulfillment.max_attempts')) {
                $this->failOrder->failLocked($order, FailureCode::AttemptsExhausted, ['attempts' => $order->attempts]);

                return FulfillmentOutcome::Failed;
            }

            $key = $this->inventory->reserve($order); // the checkout's reservation, or a new one
            if (! $key) {
                $this->failOrder->failLocked($order, FailureCode::OutOfStock);

                return FulfillmentOutcome::Failed;
            }

            $takeover = $order->status === OrderStatus::Processing;
            $lease = [
                'lease_token' => $token = (string) Str::ulid(),
                'lease_expires_at' => now()->addSeconds((int) config('gamevault.fulfillment.lease_seconds')),
                'attempts' => $order->attempts + 1,
            ];

            if ($takeover) {
                // Previous owner's lease ran out (crashed, hung, or relinquished after an error).
                $order->forceFill($lease)->save();
                $order->recordEvent('fulfillment_lease_taken_over', meta: ['attempt' => $order->attempts]);
                Log::warning('fulfillment.lease_taken_over', ['order_id' => $order->id, 'attempt' => $order->attempts]);
            } else {
                $order->transitionTo(OrderStatus::Processing, meta: ['attempt' => $lease['attempts']], attributes: $lease);
            }

            return new FulfillmentClaim($order->id, $token, $order->attempts, $key->id, $takeover);
        });
    }

    /** Step 3. Commits the delivery only if this worker still owns the order. */
    public function finalize(FulfillmentClaim $claim): FulfillmentOutcome
    {
        return Transactions::retrying(function () use ($claim): FulfillmentOutcome {
            $order = Order::query()->lockForUpdate()->find($claim->orderId);

            // Fencing check first: if the order no longer carries OUR token, someone else's claim
            // replaced ours (takeover after our lease expired, or the order was failed/re-queued
            // or finished meanwhile). Our work is discarded, whatever the order's state now is.
            // A matching token implies status "processing" (CHECK orders_lease_consistent).
            if (! $order || $order->lease_token === null || ! hash_equals($order->lease_token, $claim->token)) {
                Log::warning('fulfillment.superseded', ['order_id' => $claim->orderId, 'attempt' => $claim->attempt]);

                return FulfillmentOutcome::Superseded;
            }

            $key = GameKey::query()->whereKey($claim->keyId)->lockForUpdate()->first();
            if (! $key || $key->order_id !== $order->id || $key->status !== KeyStatus::Reserved) {
                $this->failOrder->failLocked($order, FailureCode::ReservationMissing);

                return FulfillmentOutcome::Failed;
            }

            $this->inventory->markSold($key, $order);
            $order->transitionTo(OrderStatus::Delivered, meta: ['attempt' => $claim->attempt]);

            // Same transaction as the state change: both commit or neither does.
            $this->outbox->record('order.delivered', "order.delivered:{$order->id}", $order, ['order_id' => $order->id]);

            PublishOutboxMessages::dispatch()->afterCommit();

            return FulfillmentOutcome::Delivered;
        });
    }

    /**
     * Give the lease up after a transient error so the queue retry can take over at once.
     * Fenced by the token: it never touches a lease that already belongs to someone else.
     */
    public function relinquish(FulfillmentClaim $claim): void
    {
        try {
            Order::query()
                ->whereKey($claim->orderId)
                ->where('status', OrderStatus::Processing)
                ->where('lease_token', $claim->token)
                ->update(['lease_expires_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $e) {
            // Database unavailable (often the very reason we are here): the lease expires on its own.
            Log::warning('fulfillment.relinquish_failed', ['order_id' => $claim->orderId, 'exception' => class_basename($e)]);
        }
    }

    /** Decrypts the reserved key outside any transaction. The plaintext is never logged or kept. */
    private function keyIsIntact(FulfillmentClaim $claim): bool
    {
        try {
            return GameKey::query()->findOrFail($claim->keyId)->code !== '';
        } catch (DecryptException) {
            return false;
        }
    }
}
