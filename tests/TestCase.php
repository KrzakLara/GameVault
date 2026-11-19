<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boots the application and refuses to continue unless it points at a dedicated test
     * database. RefreshDatabase runs migrate:fresh, so a misconfigured environment (for example
     * a container started with the development .env) would otherwise wipe real data.
     *
     * This lives in createApplication() rather than a trait hook so that no trait used by a
     * test class can shadow it.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || ($database !== ':memory:' && ! str_contains($database, '_test'))) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: APP_ENV=%s, database=%s. Tests must use a dedicated *_test database.',
                $app->environment(),
                $database,
            ));
        }

        return $app;
    }
}
