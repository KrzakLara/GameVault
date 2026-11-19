<?php

namespace App\Enums;

/**
 * Machine-readable reasons an order failed. Shown to admins; users see the generic message().
 */
enum FailureCode: string
{
    /** No key could be reserved (legacy orders or admin retry when stock ran out). */
    case OutOfStock = 'out_of_stock';
    /** Order was processing but its reserved key row is gone or inconsistent. */
    case ReservationMissing = 'reservation_missing';
    /** Stored ciphertext could not be decrypted (e.g. APP_KEY rotated without re-encryption). */
    case KeyIntegrity = 'key_integrity';
    /** Queue retries exhausted because of an unexpected exception. */
    case AttemptsExhausted = 'attempts_exhausted';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::OutOfStock, self::ReservationMissing, self::AttemptsExhausted => true,
            // Retrying would decrypt the same broken ciphertext again; needs operator action first.
            self::KeyIntegrity => false,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::OutOfStock => 'This item sold out before your order could be fulfilled.',
            default => 'We could not deliver this order. Our team has been notified.',
        };
    }
}
