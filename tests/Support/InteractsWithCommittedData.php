<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * For tests whose data must be COMMITTED, i.e. visible to other connections and processes
 * (multi-process concurrency tests, top-level transaction behaviour).
 *
 * RefreshDatabase cannot be used there: it wraps each test in a transaction that other
 * connections cannot see. Instead the schema is migrated once and every application table is
 * emptied before and after each test, so the suite stays isolated and repeatable and leaves no
 * rows behind for RefreshDatabase tests that run later in the same process.
 */
trait InteractsWithCommittedData
{
    protected function setUpInteractsWithCommittedData(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        $this->emptyApplicationTables();

        $this->beforeApplicationDestroyed(fn () => $this->emptyApplicationTables());
    }

    protected function emptyApplicationTables(): void
    {
        // Never leave a half-finished transaction behind (a failed assertion inside one).
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $tables = Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false);

        Schema::withoutForeignKeyConstraints(function () use ($tables) {
            foreach ($tables as $table) {
                if ($table !== 'migrations') {
                    DB::table($table)->delete();
                }
            }
        });
    }
}
