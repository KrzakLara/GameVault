<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Expected, user-facing checkout rejections. Each carries a stable code for the UI and API.
 */
final class CheckoutException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function outOfStock(): self
    {
        return new self('out_of_stock', 'Sorry, this game just sold out.', 409);
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 'This game is not available for purchase.', 404);
    }

    public static function idempotencyConflict(): self
    {
        return new self('idempotency_conflict', 'This checkout token was already used for a different purchase. Please reload the page.', 422);
    }
}
