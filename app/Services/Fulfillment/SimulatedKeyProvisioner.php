<?php

namespace App\Services\Fulfillment;

use App\Actions\Fulfillment\FulfillmentClaim;
use Illuminate\Support\Facades\Cache;

/**
 * Demo stand-in for a supplier API: adds the configured latency, which makes the "processing"
 * state visible in the UI, and can simulate a supplier outage (`gamevault:supplier-outage on`)
 * so queue retries, backoff and failed jobs can be observed on a live Horizon stack. The outage
 * switch is ignored in production.
 */
final class SimulatedKeyProvisioner implements KeyProvisioner
{
    public const OUTAGE_FLAG = 'gamevault:simulated-supplier-outage';

    public function provision(FulfillmentClaim $claim): void
    {
        $latency = (int) config('gamevault.fulfillment.simulated_latency_ms');

        if ($latency > 0) {
            usleep($latency * 1000);
        }

        if (! app()->isProduction() && Cache::get(self::OUTAGE_FLAG) === true) {
            throw new SupplierUnavailableException('Simulated supplier outage');
        }
    }
}
