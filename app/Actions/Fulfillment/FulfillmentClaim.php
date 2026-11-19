<?php

namespace App\Actions\Fulfillment;

/**
 * Proof of ownership handed out by FulfillOrder::claim(). The token is the fencing token: every
 * later write by this worker is conditional on the order still carrying it, so a worker whose
 * lease was taken over can no longer change the order.
 */
final readonly class FulfillmentClaim
{
    public function __construct(
        public int $orderId,
        public string $token,
        public int $attempt,
        public int $keyId,
        public bool $takeover,
    ) {}
}
