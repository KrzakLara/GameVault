<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fulfillment leases with fencing tokens, outbox fencing and an explicit dead-letter state, and
 * database-level integrity constraints for invariants that were previously application-only.
 *
 * Additive and backfilling like 000002: it deletes no rows. Legacy rows are normalised first so
 * every new constraint holds for existing data; if a row is genuinely inconsistent (for example a
 * key linked to an order of a different game) the migration stops with a clear message instead of
 * silently "fixing" commercial records.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const CHECKS = [
        'orders' => [
            'orders_lease_consistent', 'orders_lease_expiry_consistent', 'orders_failed_has_reason',
            'orders_failure_code_valid', 'orders_cancelled_has_timestamp', 'orders_currency_format',
        ],
        'game_keys' => ['game_keys_lifecycle_timestamps'],
        'inventory_movements' => ['inventory_movements_type_valid', 'inventory_movements_order_required'],
        'outbox_messages' => ['outbox_status_valid', 'outbox_lease_consistent', 'outbox_published_has_timestamp'],
        'games' => ['games_currency_format'],
    ];

    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        // Validate BEFORE any DDL: MySQL DDL is not transactional, so failing half-way would leave
        // a partially migrated schema behind.
        $this->assertLegacyDataIsConsistent();

        // --- orders: fulfillment lease + fencing token ------------------------------------------
        Schema::table('orders', function (Blueprint $table) {
            // Fencing token of the worker that currently owns fulfillment. A new token is issued
            // on every claim, so a stale worker can never match it again.
            $table->char('lease_token', 26)->nullable()->after('attempts');
            $table->timestamp('lease_expires_at')->nullable()->after('lease_token');
            $table->index(['status', 'lease_expires_at']);
            // Target of the composite FK below: (id, game_id) is trivially unique, but MySQL needs
            // an index whose leading columns are exactly the referenced columns.
            $table->unique(['id', 'game_id']);
        });

        // The original overlay marked orders "failed" for one reason only, an empty stock, and stored
        // neither a failure code nor a timestamp. Record that reason so failed orders always carry one.
        DB::table('orders')->where('status', 'failed')->whereNull('failure_code')->update(['failure_code' => 'out_of_stock']);
        DB::table('orders')->where('status', 'failed')->whereNull('failed_at')
            ->update(['failed_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);

        // Orders left "processing" by the pre-lease implementation receive an already-expired lease
        // so recovery can take them over.
        DB::table('orders')->where('status', 'processing')->whereNull('lease_token')->orderBy('id')
            ->eachById(fn (object $order) => DB::table('orders')->where('id', $order->id)->update([
                'lease_token' => (string) Str::ulid(),
                'lease_expires_at' => now(),
            ]));

        // --- game_keys -------------------------------------------------------------------------
        // Overlay-era rows predate the lifecycle timestamps.
        $fallback = DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)');
        DB::table('game_keys')->whereIn('status', ['reserved', 'sold'])->whereNull('reserved_at')->update(['reserved_at' => $fallback]);
        DB::table('game_keys')->where('status', 'sold')->whereNull('sold_at')->update(['sold_at' => $fallback]);

        Schema::table('game_keys', function (Blueprint $table) {
            $table->unique(['id', 'game_id']);
            // Inventory ownership: a key can only ever be linked to an order for the SAME game.
            $table->foreign(['order_id', 'game_id'], 'game_keys_order_same_game_foreign')
                ->references(['id', 'game_id'])->on('orders')->restrictOnDelete();
        });

        // --- inventory_movements ---------------------------------------------------------------
        Schema::table('inventory_movements', function (Blueprint $table) {
            // A movement's game must be its key's game, and its order's game.
            $table->foreign(['game_key_id', 'game_id'], 'inventory_movements_key_same_game_foreign')
                ->references(['id', 'game_id'])->on('game_keys')->restrictOnDelete();
            $table->foreign(['order_id', 'game_id'], 'inventory_movements_order_same_game_foreign')
                ->references(['id', 'game_id'])->on('orders')->restrictOnDelete();
        });

        // --- outbox_messages: fencing token and explicit dead-letter state ----------------------
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->char('lock_token', 26)->nullable()->after('locked_until');
            $table->timestamp('dead_lettered_at')->nullable()->after('published_at');
        });

        if ($mysql) {
            $this->dropCheckIfExists('outbox_messages', 'outbox_status_valid');
        }

        DB::table('outbox_messages')->where('status', 'failed')->update([
            'status' => 'dead_letter',
            'dead_lettered_at' => DB::raw('COALESCE(updated_at, CURRENT_TIMESTAMP)'),
        ]);
        // Claimed by the pre-fencing publisher: hand back to the queue when its lease would have ended.
        DB::table('outbox_messages')->where('status', 'processing')->update([
            'status' => 'pending',
            'available_at' => DB::raw('COALESCE(locked_until, CURRENT_TIMESTAMP)'),
            'locked_until' => null,
        ]);

        if ($mysql) {
            $failureCodes = "'out_of_stock','reservation_missing','key_integrity','attempts_exhausted'";

            $statements = [
                "ALTER TABLE orders ADD CONSTRAINT orders_lease_consistent CHECK ((status = 'processing') = (lease_token IS NOT NULL))",
                'ALTER TABLE orders ADD CONSTRAINT orders_lease_expiry_consistent CHECK ((lease_token IS NULL) = (lease_expires_at IS NULL))',
                "ALTER TABLE orders ADD CONSTRAINT orders_failed_has_reason CHECK (status <> 'failed' OR (failure_code IS NOT NULL AND failed_at IS NOT NULL))",
                "ALTER TABLE orders ADD CONSTRAINT orders_failure_code_valid CHECK (failure_code IS NULL OR failure_code IN ({$failureCodes}))",
                "ALTER TABLE orders ADD CONSTRAINT orders_cancelled_has_timestamp CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)",
                "ALTER TABLE orders ADD CONSTRAINT orders_currency_format CHECK (REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c'))",
                "ALTER TABLE games ADD CONSTRAINT games_currency_format CHECK (REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c'))",
                "ALTER TABLE game_keys ADD CONSTRAINT game_keys_lifecycle_timestamps CHECK (
                    (status <> 'sold' OR sold_at IS NOT NULL) AND (status = 'available' OR reserved_at IS NOT NULL))",
                "ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_valid
                    CHECK (type IN ('imported','reserved','released','sold'))",
                "ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_order_required
                    CHECK (type = 'imported' OR order_id IS NOT NULL)",
                "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_status_valid
                    CHECK (status IN ('pending','processing','published','dead_letter'))",
                "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_lease_consistent CHECK ((status = 'processing') = (lock_token IS NOT NULL))",
                "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_published_has_timestamp CHECK (status <> 'published' OR published_at IS NOT NULL)",
            ];

            foreach ($statements as $sql) {
                DB::statement($sql);
            }
        }
    }

    /**
     * Every step checks existence first: MySQL DDL is not transactional, so a rollback that was
     * interrupted half-way must be safe to run again.
     */
    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        if ($mysql) {
            foreach (self::CHECKS as $table => $names) {
                foreach ($names as $name) {
                    $this->dropCheckIfExists($table, $name);
                }
            }
        }

        // When the composite (x_id, game_id) indexes were created, MySQL silently dropped the
        // original single-column FK indexes because the composite prefix could serve those FKs.
        // Restore them before dropping the composite ones, or MySQL refuses ("needed in a FK").
        $this->dropForeignIfExists('inventory_movements', 'inventory_movements_key_same_game_foreign');
        $this->dropForeignIfExists('inventory_movements', 'inventory_movements_order_same_game_foreign');
        $this->ensureIndex('inventory_movements', 'inventory_movements_game_key_id_foreign', ['game_key_id']);
        $this->ensureIndex('inventory_movements', 'inventory_movements_order_id_foreign', ['order_id']);
        $this->dropIndexIfExists('inventory_movements', 'inventory_movements_key_same_game_foreign');
        $this->dropIndexIfExists('inventory_movements', 'inventory_movements_order_same_game_foreign');

        $this->dropForeignIfExists('game_keys', 'game_keys_order_same_game_foreign');
        $this->dropIndexIfExists('game_keys', 'game_keys_order_same_game_foreign');
        $this->dropIndexIfExists('game_keys', 'game_keys_id_game_id_unique');

        $this->dropIndexIfExists('orders', 'orders_id_game_id_unique');
        $this->dropIndexIfExists('orders', 'orders_status_lease_expires_at_index');
        $this->dropColumnsIfExist('orders', ['lease_token', 'lease_expires_at']);

        DB::table('outbox_messages')->where('status', 'dead_letter')->update(['status' => 'failed']);
        DB::table('outbox_messages')->where('status', 'processing')->update(['status' => 'pending']);
        $this->dropColumnsIfExist('outbox_messages', ['lock_token', 'dead_lettered_at']);

        if ($mysql) {
            $this->dropCheckIfExists('outbox_messages', 'outbox_status_valid');
            DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_status_valid
                CHECK (status IN ('pending','processing','published','failed'))");
        }
    }

    /** Rows no backfill can repair without guessing about commercial records: stop and report. */
    private function assertLegacyDataIsConsistent(): void
    {
        $problems = array_filter([
            'keys linked to an order of a different game' => DB::table('game_keys')->join('orders', 'orders.id', '=', 'game_keys.order_id')
                ->whereColumn('orders.game_id', '<>', 'game_keys.game_id')->pluck('game_keys.id')->all(),
            'movements whose game differs from their key' => DB::table('inventory_movements')->join('game_keys', 'game_keys.id', '=', 'inventory_movements.game_key_id')
                ->whereColumn('game_keys.game_id', '<>', 'inventory_movements.game_id')->pluck('inventory_movements.id')->all(),
            'movements whose game differs from their order' => DB::table('inventory_movements')->join('orders', 'orders.id', '=', 'inventory_movements.order_id')
                ->whereColumn('orders.game_id', '<>', 'inventory_movements.game_id')->pluck('inventory_movements.id')->all(),
        ]);

        if ($problems !== []) {
            throw new RuntimeException('Fix these rows before migrating (nothing was changed): '.json_encode($problems));
        }
    }

    private function dropForeignIfExists(string $table, string $name): void
    {
        if (collect(Schema::getForeignKeys($table))->contains('name', $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($name));
        }
    }

    /** @param  list<string>  $columns */
    private function ensureIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    /** @param  list<string>  $columns */
    private function dropColumnsIfExist(string $table, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $c) => Schema::hasColumn($table, $c)));
        if ($existing !== []) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($existing));
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }

    private function dropCheckIfExists(string $table, string $name): void
    {
        $exists = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();

        if ($exists) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};
