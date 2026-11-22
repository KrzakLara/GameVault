<?php

namespace App\Console\Commands;

use App\Support\E2eGuard;
use Database\Seeders\E2eSeeder;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Rebuilds the isolated E2E database with deterministic fixtures. The guard runs BEFORE the
 * destructive migrate:fresh, so this can never wipe the development or test database: it needs
 * APP_ENV=e2e and a database name ending in _e2e (docker-compose.yml, profile "e2e").
 */
#[AsCommand(name: 'e2e:reset')]
class ResetE2eEnvironment extends Command
{
    protected $signature = 'e2e:reset';

    protected $description = 'Rebuild the isolated E2E database with deterministic fixtures (APP_ENV=e2e only)';

    public function handle(): int
    {
        try {
            E2eGuard::assertIsolated();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Resetting database '.config('database.connections.'.config('database.default').'.database'));

        return $this->call('migrate:fresh', ['--force' => true, '--seed' => true, '--seeder' => E2eSeeder::class]);
    }
}
