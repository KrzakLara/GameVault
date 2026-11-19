<?php

namespace App\Enums;

/**
 * Order lifecycle. Allowed transitions are the single source of truth for the state machine;
 * the database additionally enforces the set of valid values with a CHECK constraint (MySQL).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Failed],
            self::Processing => [self::Delivered, self::Failed],
            // Admin-only: retry (back to pending) or close permanently.
            self::Failed => [self::Pending, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isInFlight(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
