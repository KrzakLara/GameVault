<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property array<string, mixed> $payload Identifiers only (enforced by Outbox::record()).
 * @property OutboxStatus $status
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $locked_until
 * @property string|null $lock_token Fencing token of the publisher that claimed the message.
 * @property Carbon|null $published_at
 * @property Carbon|null $dead_lettered_at
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OutboxMessage extends Model
{
    /**
     * Messages are written by Outbox::record() and state-managed by OutboxPublisher only.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $hidden = ['lock_token'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'aggregate_id' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_until' => 'datetime',
            'published_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
        ];
    }
}
