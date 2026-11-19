<?php

namespace App\Services\Fulfillment;

use App\Actions\Fulfillment\FulfillmentClaim;

/**
 * Integration point for the external side of fulfillment (supplier hand-off, key activation).
 *
 * Contract:
 *  - Always called OUTSIDE any database transaction, so slow I/O never holds row locks and no
 *    external side effect happens on behalf of a transaction that might still roll back.
 *  - May run more than once for the same order (queue redelivery, lease takeover). A real
 *    implementation must be idempotent per order, e.g. by sending the order's public id as
 *    the supplier's idempotency key.
 *  - Throwing signals a transient failure: the claim's lease is relinquished and the queue
 *    retries with backoff.
 */
interface KeyProvisioner
{
    public function provision(FulfillmentClaim $claim): void;
}
