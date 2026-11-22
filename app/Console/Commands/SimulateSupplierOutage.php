<?php

namespace App\Console\Commands;

use App\Services\Fulfillment\SimulatedKeyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fault injection for demos and live queue verification: while on, the simulated supplier fails
 * every hand-off, so DeliverGameKey retries with backoff and finally fails the order (key back in
 * stock, failed job recorded). Refused in production; the flag also expires on its own.
 */
#[AsCommand(name: 'gamevault:supplier-outage')]
class SimulateSupplierOutage extends Command
{
    protected $signature = 'gamevault:supplier-outage {state : on or off} {--minutes=30 : Expire the simulated outage after this many minutes}';

    protected $description = 'Simulate a supplier outage to observe fulfillment retries (non-production only)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to simulate a supplier outage in production.');

            return self::FAILURE;
        }

        $state = $this->argument('state');

        if ($state === 'on') {
            Cache::put(SimulatedKeyProvisioner::OUTAGE_FLAG, true, now()->addMinutes(max(1, (int) $this->option('minutes'))));
            $this->components->warn('Simulated supplier outage is ON: every fulfillment attempt fails until it is turned off.');

            return self::SUCCESS;
        }

        if ($state === 'off') {
            Cache::forget(SimulatedKeyProvisioner::OUTAGE_FLAG);
            $this->components->info('Simulated supplier outage is OFF.');

            return self::SUCCESS;
        }

        $this->error('State must be "on" or "off".');

        return self::INVALID;
    }
}
