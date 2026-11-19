<?php

namespace App\Services\Outbox;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * Producer side of the transactional outbox. A message is written in the SAME transaction as
 * the business state change, so either both commit or neither does, and nothing is published
 * for state that never committed.
 */
final class Outbox
{
    /** Payload keys allowed besides "*_id" identifiers. */
    private const EXTRA_PAYLOAD_KEYS = ['occurred_at', 'status'];

    /**
     * @param  string  $eventId  Stable business identifier; recording it again is a no-op.
     * @param  array<string, int|string|bool|null>  $payload  Identifiers only. Consumers load
     *                                                        current data themselves, so no
     *                                                        secret or personal data is copied
     *                                                        into the outbox or the logs.
     * @return bool False when the event had already been recorded.
     */
    public function record(string $type, string $eventId, Model $aggregate, array $payload): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox messages must be recorded inside the business transaction.');
        }

        self::assertSafePayload($payload);

        try {
            // Nested transaction = SAVEPOINT: a duplicate undoes only this INSERT, never the
            // caller's business changes. (Unlike INSERT IGNORE, which on MySQL would also
            // silently swallow truncation, CHECK and FK errors and lose the message.)
            DB::transaction(fn () => OutboxMessage::query()->insert([
                'event_id' => $eventId,
                'type' => $type,
                'aggregate_type' => class_basename($aggregate),
                'aggregate_id' => $aggregate->getKey(),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => OutboxStatus::Pending->value,
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            Log::notice('outbox.duplicate_event_ignored', ['event_id' => $eventId]);

            return false;
        }

        Log::info('outbox.recorded', ['event_id' => $eventId, 'type' => $type]);

        return true;
    }

    /** @param  array<mixed>  $payload */
    public static function assertSafePayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $allowedKey = is_string($key) && (preg_match('/^[a-z][a-z0-9_]*_id$/', $key) === 1 || in_array($key, self::EXTRA_PAYLOAD_KEYS, true));

            if (! $allowedKey || ! (is_int($value) || is_string($value) || is_bool($value) || $value === null)) {
                throw new InvalidArgumentException("Outbox payload field [{$key}] is not allowed: payloads carry identifiers only.");
            }

            if (is_string($value) && strlen($value) > 64) {
                throw new InvalidArgumentException("Outbox payload field [{$key}] is too long for an identifier.");
            }
        }
    }
}
