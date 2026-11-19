<?php

namespace Tests\Support\Concurrency;

use App\Actions\Fulfillment\FulfillmentClaim;
use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Services\Fulfillment\KeyProvisioner;
use App\Services\Outbox\Handlers\OrderDeliveredHandler;
use Illuminate\Support\Facades\DB;

/**
 * Test-only instrumentation installed inside worker processes.
 *
 * "Race wideners" sleep at precise points INSIDE the application's own transactions, while it
 * holds its row locks. That turns a probabilistic race into a deterministic one: every worker is
 * guaranteed to be in its critical section at the same time, contending for the same rows.
 *
 * Fakes replace the two external integration points (key provisioning, outbox handlers) so a
 * test can make them slow, record what happened, or SIGKILL the worker at an exact moment.
 */
final class Hooks
{
    /** @param  array<string, mixed>  $hooks */
    public static function install(array $hooks): void
    {
        if ($ms = (int) ($hooks['sleep_after_order_created_ms'] ?? 0)) {
            // Inside the checkout transaction, right after the order INSERT: the
            // (user_id, idempotency_key) unique-index entry is now locked by this transaction.
            Order::created(fn () => usleep($ms * 1000));
        }

        if ($ms = (int) ($hooks['sleep_after_key_reserved_ms'] ?? 0)) {
            // Inside the reserving transaction, while it holds the key's row lock.
            InventoryMovement::created(function (InventoryMovement $movement) use ($ms) {
                if ($movement->type === InventoryMovementType::Reserved) {
                    usleep($ms * 1000);
                }
            });
        }

        if (isset($hooks['provision'])) {
            $options = (array) $hooks['provision'];
            app()->instance(KeyProvisioner::class, new class($options) implements KeyProvisioner
            {
                /** @param  array<string, mixed>  $options */
                public function __construct(private array $options) {}

                public function provision(FulfillmentClaim $claim): void
                {
                    Hooks::append($this->options['record_to'] ?? null, [
                        'event' => 'provision', 'order_id' => $claim->orderId, 'attempt' => $claim->attempt,
                        'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
                    ]);

                    if ($touch = $this->options['touch_when_started'] ?? null) {
                        touch((string) $touch);
                    }

                    usleep((int) ($this->options['sleep_ms'] ?? 0) * 1000);

                    if (! empty($this->options['crash'])) {
                        posix_kill((int) getmypid(), SIGKILL); // hard crash: no catch, no finally
                    }
                    if (! empty($this->options['throw'])) {
                        throw new \RuntimeException('Simulated supplier outage');
                    }
                }
            });
        }

        if (isset($hooks['outbox_handler'])) {
            $options = (array) $hooks['outbox_handler'];
            app()->instance(OrderDeliveredHandler::class, new class($options)
            {
                /** @param  array<string, mixed>  $options */
                public function __construct(private array $options) {}

                public function handle(OutboxMessage $message): void
                {
                    usleep((int) ($this->options['sleep_ms'] ?? 0) * 1000);

                    // The "external side effect" (e-mail sent), recorded durably.
                    Hooks::append($this->options['record_to'] ?? null, [
                        'event' => 'handled', 'event_id' => $message->event_id, 'attempts' => $message->attempts, 'pid' => getmypid(),
                    ]);

                    if (! empty($this->options['crash_after_handle'])) {
                        posix_kill((int) getmypid(), SIGKILL); // dies before marking it published
                    }
                }
            });
        }
    }

    /**
     * Each process appends to its OWN file ("<path>.<pid>"), so no two processes ever write the
     * same file: cross-process append/lock semantics are unreliable on some bind mounts
     * (Docker Desktop on Windows), which once made a record go missing.
     *
     * @param  array<string, mixed>  $record
     */
    public static function append(?string $file, array $record): void
    {
        if ($file) {
            file_put_contents($file.'.'.getmypid(), json_encode($record).PHP_EOL, FILE_APPEND);
        }
    }

    /** @return list<array<string, mixed>> All records written by all processes for this path. */
    public static function read(string $file): array
    {
        $records = [];
        foreach (glob($file.'.*') ?: [] as $part) {
            foreach (array_filter(explode(PHP_EOL, (string) file_get_contents($part))) as $line) {
                $records[] = (array) json_decode($line, true);
            }
        }

        return $records;
    }
}
