<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * Append-only record of privileged or security-relevant actions (admin retries, key reveals,
 * inventory imports). Never stores key material.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip', 'request_id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>  $meta  Identifiers and reasons only, never key material.
     */
    public static function record(string $action, ?User $actor, ?Model $subject = null, array $meta = []): self
    {
        // Console commands and queue workers get a synthetic request; only a routed HTTP request
        // has a meaningful client IP.
        $request = request();

        return self::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta + ['via' => $request->route() ? 'http' : 'console'],
            'ip' => $request->route() ? $request->ip() : null,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
