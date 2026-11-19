<?php

use App\Support\KeyFingerprint;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Evolves the original GameVault schema in place. Additive and backfilling: it is safe to run
 * on a local database that already contains overlay data, and it deletes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fingerprint every key BEFORE any DDL. MySQL DDL is not transactional: discovering
        // duplicate codes only when the UNIQUE index is built would leave a half-migrated schema.
        // The original overlay never enforced unique codes, so duplicates are possible in old data;
        // selling both copies would hand one code to two customers, so an operator must decide.
        $hashes = [];
        DB::table('game_keys')->orderBy('id')->select(['id', 'code'])->chunk(500, function ($keys) use (&$hashes) {
            foreach ($keys as $key) {
                try {
                    $hashes[$key->id] = KeyFingerprint::of(Crypt::decryptString($key->code));
                } catch (DecryptException) {
                    // left NULL; surfaced as key_integrity if it is ever fulfilled
                }
            }
        });
        $duplicates = array_keys(array_filter(array_count_values($hashes), fn (int $n) => $n > 1));
        if ($duplicates !== []) {
            $ids = array_keys(array_filter($hashes, fn (string $h) => in_array($h, $duplicates, true)));
            throw new RuntimeException('Duplicate key codes in stock, resolve before migrating (nothing was changed). Key ids: '.implode(', ', $ids));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->string('genre', 32)->default('Action')->after('platform');
            $table->char('currency', 3)->default('EUR')->after('price_cents');
            $table->boolean('is_active')->default(true)->after('description');
            $table->boolean('is_featured')->default(false)->after('is_active');
            $table->index(['is_active', 'platform']);
            $table->index(['is_active', 'genre']);
        });

        // --- orders -------------------------------------------------------------------------
        Schema::table('orders', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->after('id');
            $table->char('currency', 3)->default('EUR')->after('price_cents');
            $table->string('idempotency_key', 64)->nullable()->after('currency');
            $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->string('failure_code', 32)->nullable()->after('status');
            $table->unsignedSmallInteger('attempts')->default(0)->after('failure_code');
            $table->timestamp('processing_started_at')->nullable()->after('attempts');
            $table->timestamp('failed_at')->nullable()->after('delivered_at');
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');
        });

        DB::table('orders')->whereNull('public_id')->orderBy('id')->eachById(function (object $order) {
            DB::table('orders')->where('id', $order->id)->update(['public_id' => (string) Str::ulid()]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable(false)->change();
            $table->unique('public_id');
            // One logical checkout per (user, idempotency key). NULLs (legacy rows) do not collide.
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);

            // Orders are commercial records: deleting a user anonymises them instead of
            // cascading them away (which previously orphaned sold keys).
            $table->dropForeign(['user_id']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        // The composite (user_id, idempotency_key) index now backs the user FK. Drop a single-column
        // user_id index left over by an earlier down(), so up() yields the same schema every time.
        foreach (['orders_user_id_index', 'orders_user_id_foreign'] as $index) {
            if (Schema::hasIndex('orders', $index)) {
                Schema::table('orders', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }

        // --- game_keys ----------------------------------------------------------------------
        Schema::table('game_keys', function (Blueprint $table) {
            $table->char('code_hash', 64)->nullable()->after('code');
            $table->timestamp('reserved_at')->nullable()->after('order_id');
            $table->timestamp('sold_at')->nullable()->after('reserved_at');
            $table->dropForeign(['order_id']);
        });
        Schema::table('game_keys', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        foreach ($hashes as $id => $hash) {
            DB::table('game_keys')->where('id', $id)->update(['code_hash' => $hash]);
        }

        Schema::table('game_keys', function (Blueprint $table) {
            $table->unique('code_hash');
        });

        // --- supporting tables --------------------------------------------------------------
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_key_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['game_id', 'id']);
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            // Stable business identifier, e.g. "order.delivered:42". Recording the same event
            // twice is rejected by this constraint, which makes the producer side idempotent.
            $table->string('event_id', 120)->unique();
            $table->string('type', 60);
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'id']);
        });

        // Defensive: the overlay always stamped delivered_at, but the CHECK below must never fail
        // half-way through on an old row that lacks it.
        DB::table('orders')->where('status', 'delivered')->whereNull('delivered_at')
            ->update(['delivered_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);

        if (DB::getDriverName() === 'mysql') {
            // MySQL >= 8.0.16 enforces CHECK constraints. These mirror the application rules so a
            // bug or a manual UPDATE cannot persist an impossible state.
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid
                CHECK (status IN ('pending','processing','delivered','failed','cancelled'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivered_has_timestamp
                CHECK (status <> 'delivered' OR delivered_at IS NOT NULL)");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_price_non_negative CHECK (price_cents >= 0)');
            DB::statement("ALTER TABLE game_keys ADD CONSTRAINT game_keys_status_matches_order CHECK (
                (status = 'available' AND order_id IS NULL)
                OR (status IN ('reserved','sold') AND order_id IS NOT NULL))");
            DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_status_valid
                CHECK (status IN ('pending','processing','published','failed'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $checks = ['orders' => ['orders_status_valid', 'orders_delivered_has_timestamp', 'orders_price_non_negative'],
                'game_keys' => ['game_keys_status_matches_order']];
            foreach ($checks as $table => $names) {
                foreach ($names as $name) {
                    $exists = DB::table('information_schema.check_constraints')
                        ->where('constraint_schema', DB::getDatabaseName())->where('constraint_name', $name)->exists();
                    if ($exists) {
                        DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
                    }
                }
            }
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('order_events');

        Schema::table('game_keys', function (Blueprint $table) {
            $table->dropUnique(['code_hash']);
            $table->dropColumn(['code_hash', 'reserved_at', 'sold_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // The FK uses the composite unique index; drop it first, restore the original after.
            $table->dropForeign(['user_id']);
        });
        if (! Schema::hasIndex('orders', ['user_id'])) {
            // Same name as the index MySQL auto-created for the original FK in 000001.
            Schema::table('orders', fn (Blueprint $t) => $t->index('user_id', 'orders_user_id_foreign'));
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropUnique(['public_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn([
                'public_id', 'currency', 'idempotency_key', 'request_fingerprint', 'failure_code',
                'attempts', 'processing_started_at', 'failed_at', 'cancelled_at',
            ]);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'platform']);
            $table->dropIndex(['is_active', 'genre']);
            $table->dropColumn(['genre', 'currency', 'is_active', 'is_featured']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
