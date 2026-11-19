<?php

namespace App\Services\Fulfillment;

use App\Actions\Fulfillment\FulfillmentClaim;

/**
 * Demo stand-in for a supplier API: only adds the configured latency, which makes the
 * "processing" state visible in the UI.
 */
final class SimulatedKeyProvisioner implements KeyProvisioner
{
    public function provision(FulfillmentClaim $claim): void
    {
        $latency = (int) config('gamevault.fulfillment.simulated_latency_ms');

        if ($latency > 0) {
            usleep($latency * 1000);
        }
    }
}
