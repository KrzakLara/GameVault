<?php

namespace App\Services\Outbox;

use App\Enums\OutboxStatus;
use App\Models\AuditLog;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Outbox\Handlers\OrderDeliveredHandler;
use App\Support\Transactions;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Consumer side of the transactional outbox.
 *
 * Delivery guarantee: AT-LEAST-ONCE per message. A publisher that dies after the handler's side
 * effect but before marking the message published causes it to be handled again once its lease
 * expires. Exactly-once delivery to an external system (email, webhook) is impossible without
 * the receiver's cooperation (an idempotency key it honours); handlers must tolerate duplicates.
 * What IS guaranteed: business state never changes here (it committed with the message), a
 * message is never claimed by two live publishers at once, and a stale publisher can never
 * overwrite the outcome recorded by a newer one (fencing token).
 *
 * State machine: pending -> processing -> published
 *                               |-> pending (retry, exponential backoff) -> ... -> dead_letter
 */
final class OutboxPublisher
{
    /** @var array<string, class-string<OutboxHandler>> */
    private const HANDLERS = [
        'order.delivered' => OrderDeliveredHandler::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return array{published: int, retried: int, dead_lettered: int, stale: int} */
    public function publishBatch(?int $limit = null): array
    {
        $stats = ['published' => 0, 'retried' => 0, 'dead_lettered' => 0, 'stale' => 0];

        foreach ($this->claim($limit ?? (int) config('gamevault.outbox.batch_size')) as $message) {
            $stats[$this->deliver($message)]++;
        }

        return $stats;
    }

    /**
     * Claim due messages under a lease, each with a fresh fencing token.
     *
     * While a message is processing, available_at holds its lease expiry, so the same indexed
     * condition (status, available_at) finds pending messages that are due AND claimed messages
     * whose publisher died (stuck-message recovery). SKIP LOCKED lets concurrent publishers take
     * disjoint batches without waiting on each other.
     *
     * @return Collection<int, OutboxMessage>
     */
    public function claim(int $limit): Collection
    {
        return Transactions::retrying(function () use ($limit): Collection {
            $now = now();
            $leaseUntil = $now->copy()->addSeconds((int) config('gamevault.outbox.lease_seconds'));

            $messages = $this->lockDue(OutboxStatus::Pending, $limit, $now);
            if ($messages->count() < $limit) {
                $messages = $messages->concat($this->lockDue(OutboxStatus::Processing, $limit - $messages->count(), $now));
            }

            foreach ($messages as $message) {
                $reclaimed = $message->status === OutboxStatus::Processing;

                $message->forceFill([
                    'status' => OutboxStatus::Processing,
                    'attempts' => $message->attempts + 1,
                    'lock_token' => (string) Str::ulid(),
                    'locked_until' => $leaseUntil,
                    'available_at' => $leaseUntil,
                ])->save();

                Log::log($reclaimed ? 'warning' : 'info', $reclaimed ? 'outbox.reclaimed_expired_lease' : 'outbox.claimed', [
                    'event_id' => $message->event_id, 'attempts' => $message->attempts,
                ]);
            }

            return $messages;
        });
    }

    /**
     * Locks at most $limit due rows, by walking the (status, available_at, id) index in order.
     *
     * Any plan with a filesort reads, and so LOCKS, every due row before LIMIT applies: one
     * claimer then holds the whole backlog and concurrent claimers SKIP LOCKED all of it. Two
     * things guarantee the index walk, both found by measurement:
     *  - one status per query: "status IN (...)" + ORDER BY available_at needs a filesort
     *    (6 publishers: three claimed nothing, 15 of 60 messages left behind);
     *  - FORCE INDEX: with stale statistics (e.g. computed on an empty table before a burst) the
     *    optimizer chose a full scan + filesort even for one status (a full-suite run: 5 of 6
     *    publishers claimed nothing). Guarded by tests/Integration/Database/QueryPlanTest.
     *
     * @return Collection<int, OutboxMessage>
     */
    private function lockDue(OutboxStatus $status, int $limit, CarbonInterface $now): Collection
    {
        return OutboxMessage::query()
            ->forceIndex('outbox_messages_status_available_at_index')
            ->where('status', $status)
            ->where('available_at', '<=', $now)
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->lock('for update skip locked')
            ->get();
    }

    /** @return 'published'|'retried'|'dead_lettered'|'stale' */
    public function deliver(OutboxMessage $message): string
    {
        try {
            $handler = self::HANDLERS[$message->type] ?? throw new RuntimeException("No outbox handler for [{$message->type}].");
            $this->container->make($handler)->handle($message);
        } catch (Throwable $e) {
            return $this->recordFailure($message, $e);
        }

        return $this->complete($message);
    }

    /** @return 'published'|'stale' */
    private function complete(OutboxMessage $message): string
    {
        $updated = $this->fenced($message)->update([
            'status' => OutboxStatus::Published->value,
            'published_at' => now(),
            'locked_until' => null,
            'lock_token' => null,
            'last_error' => null,
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            Log::warning('outbox.stale_completion_ignored', ['event_id' => $message->event_id, 'attempts' => $message->attempts]);

            return 'stale';
        }

        Log::info('outbox.published', ['event_id' => $message->event_id, 'attempts' => $message->attempts]);

        return 'published';
    }

    /** @return 'retried'|'dead_lettered'|'stale' */
    private function recordFailure(OutboxMessage $message, Throwable $e): string
    {
        $deadLetter = $message->attempts >= (int) config('gamevault.outbox.max_attempts');
        $delay = self::backoffSeconds($message->attempts);

        $updated = $this->fenced($message)->update([
            'status' => ($deadLetter ? OutboxStatus::DeadLetter : OutboxStatus::Pending)->value,
            'available_at' => $deadLetter ? now() : now()->addSeconds($delay),
            'locked_until' => null,
            'lock_token' => null,
            'dead_lettered_at' => $deadLetter ? now() : null,
            'last_error' => self::describe($e),
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            Log::warning('outbox.stale_failure_ignored', ['event_id' => $message->event_id, 'attempts' => $message->attempts]);

            return 'stale';
        }

        Log::warning($deadLetter ? 'outbox.dead_lettered' : 'outbox.retry_scheduled', [
            'event_id' => $message->event_id,
            'attempts' => $message->attempts,
            'retry_in_seconds' => $deadLetter ? null : $delay,
            'exception' => class_basename($e),
        ]);

        return $deadLetter ? 'dead_lettered' : 'retried';
    }

    /** Exponential backoff after the given number of failed attempts: 30 s, 60 s, 120 s ... capped. */
    public static function backoffSeconds(int $attempts): int
    {
        $base = (int) config('gamevault.outbox.backoff_base_seconds');
        $cap = (int) config('gamevault.outbox.backoff_cap_seconds');

        return (int) min($cap, $base * (2 ** max(0, $attempts - 1)));
    }

    /**
     * Operator action: move dead-lettered messages back to pending with a fresh attempt budget.
     *
     * @return int Number of messages requeued.
     */
    public function requeueDeadLetters(?User $actor, ?int $id = null): int
    {
        return DB::transaction(function () use ($actor, $id): int {
            $ids = OutboxMessage::query()->where('status', OutboxStatus::DeadLetter)
                ->when($id, fn ($q) => $q->whereKey($id))
                ->lockForUpdate()->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            OutboxMessage::query()->whereKey($ids)->update([
                'status' => OutboxStatus::Pending->value,
                'attempts' => 0,
                'available_at' => now(),
                'dead_lettered_at' => null,
                'updated_at' => now(),
            ]);

            AuditLog::record('outbox.requeue', $actor, null, ['message_ids' => $ids->all(), 'count' => $ids->count()]);
            Log::warning('outbox.requeued', ['count' => $ids->count()]);

            return $ids->count();
        });
    }

    /**
     * Observability snapshot for dashboards and the outbox:status command.
     *
     * @return array{counts: array<string, int>, oldest_due_seconds: int|null, expired_leases: int}
     */
    public function stats(): array
    {
        $counts = OutboxMessage::query()->toBase()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(fn ($n) => (int) $n)->all();

        $oldestDue = OutboxMessage::query()->toBase()->where('status', OutboxStatus::Pending->value)
            ->where('available_at', '<=', now())->min('available_at');

        return [
            'counts' => array_merge(array_fill_keys(array_column(OutboxStatus::cases(), 'value'), 0), $counts),
            'oldest_due_seconds' => $oldestDue ? (int) now()->diffInSeconds($oldestDue, absolute: true) : null,
            'expired_leases' => OutboxMessage::query()->where('status', OutboxStatus::Processing)->where('locked_until', '<', now())->count(),
        ];
    }

    /**
     * Conditional on the claim we hold: a stale publisher's write matches zero rows.
     *
     * @return Builder<OutboxMessage>
     */
    private function fenced(OutboxMessage $message): Builder
    {
        return OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', OutboxStatus::Processing)
            ->where('lock_token', $message->lock_token);
    }

    /** Exception summary safe to store: class, truncated message, e-mail addresses redacted. */
    private static function describe(Throwable $e): string
    {
        $message = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $e->getMessage());

        return Str::limit(class_basename($e).': '.$message, 480);
    }
}
