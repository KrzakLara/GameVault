<?php

/*
 * Migrations on real MySQL 8.4: a clean install, a complete rollback and re-apply compared
 * against an exact schema snapshot, and the upgrade of a POPULATED overlay-era database whose
 * rows are written exactly as the original overlay code wrote them.
 *
 * Runs only against the disposable *_test database (guarded by Tests\TestCase); the full schema
 * is rebuilt after every test so later tests are unaffected. No real data is ever touched.
 */

use App\Actions\Fulfillment\FulfillmentOutcome;
use App\Actions\Fulfillment\FulfillOrder;
use App\Support\KeyFingerprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithCommittedData;

uses(InteractsWithCommittedData::class);

afterEach(function () {
    Artisan::call('migrate:fresh'); // leave the complete, current schema behind
});

/** Columns, indexes and constraints of the test schema, normalised for comparison. */
function schemaSnapshot(): array
{
    $db = DB::getDatabaseName();

    return [
        'columns' => DB::select('SELECT table_name, column_name, column_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, column_name', [$db]),
        'indexes' => DB::select('SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols FROM information_schema.statistics WHERE table_schema = ? GROUP BY table_name, index_name, non_unique ORDER BY table_name, index_name', [$db]),
        'constraints' => DB::select('SELECT table_name, constraint_name, constraint_type FROM information_schema.table_constraints WHERE constraint_schema = ? ORDER BY table_name, constraint_name', [$db]),
        'checks' => DB::select('SELECT constraint_name, check_clause FROM information_schema.check_constraints WHERE constraint_schema = ? ORDER BY constraint_name', [$db]),
    ];
}

/** Go back to the schema the original overlay created (before 000002/000003). */
function migrateToOverlaySchema(): void
{
    Artisan::call('migrate:fresh');
    Artisan::call('migrate:rollback', ['--step' => 2]);
    expect(Schema::hasColumn('orders', 'public_id'))->toBeFalse()->and(Schema::hasTable('outbox_messages'))->toBeFalse();
}

it('migrates a clean database, rolls every GameVault migration back, and re-applies to an identical schema', function () {
    Artisan::call('migrate:fresh');
    $installed = schemaSnapshot();
    expect(collect($installed['checks'])->pluck('constraint_name'))->toHaveCount(17); // 4 from 000002 (+1 replaced) + 13 from 000003

    expect(Artisan::call('migrate:rollback', ['--step' => 3]))->toBe(0)
        ->and(Schema::hasTable('games'))->toBeFalse()
        ->and(Schema::hasTable('outbox_messages'))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue(); // starter tables untouched

    expect(Artisan::call('migrate'))->toBe(0)
        ->and(schemaSnapshot())->toEqual($installed);
});

it('upgrades a populated overlay-era database without losing or corrupting data', function () {
    migrateToOverlaySchema();
    $now = now()->subDay()->startOfSecond();

    // Rows exactly as the original overlay wrote them: string statuses, no public ids, no
    // reservation timestamps, "failed" without a reason, encrypted codes without fingerprints.
    $alice = DB::table('users')->insertGetId(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => Hash::make('secret'), 'created_at' => $now, 'updated_at' => $now]);
    $bob = DB::table('users')->insertGetId(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => Hash::make('secret'), 'created_at' => $now, 'updated_at' => $now]);
    $game = DB::table('games')->insertGetId(['name' => 'Legacy One', 'slug' => 'legacy-one', 'platform' => 'Steam', 'price_cents' => 1999, 'created_at' => $now, 'updated_at' => $now]);
    $other = DB::table('games')->insertGetId(['name' => 'Legacy Two', 'slug' => 'legacy-two', 'platform' => 'Xbox', 'price_cents' => 999, 'created_at' => $now, 'updated_at' => $now]);
    $delivered = DB::table('orders')->insertGetId(['user_id' => $alice, 'game_id' => $game, 'status' => 'delivered', 'price_cents' => 1999, 'delivered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $pending = DB::table('orders')->insertGetId(['user_id' => $bob, 'game_id' => $game, 'status' => 'pending', 'price_cents' => 1999, 'created_at' => $now, 'updated_at' => $now]);
    $failed = DB::table('orders')->insertGetId(['user_id' => $bob, 'game_id' => $other, 'status' => 'failed', 'price_cents' => 999, 'created_at' => $now, 'updated_at' => $now]);
    $codes = ['LEGACY-SOLD1-11111', 'LEGACY-FREE1-22222', 'LEGACY-FREE2-33333', 'LEGACY-XBOX1-44444'];
    $soldKey = DB::table('game_keys')->insertGetId(['game_id' => $game, 'code' => Crypt::encryptString($codes[0]), 'status' => 'sold', 'order_id' => $delivered, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('game_keys')->insert([
        ['game_id' => $game, 'code' => Crypt::encryptString($codes[1]), 'status' => 'available', 'order_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ['game_id' => $game, 'code' => Crypt::encryptString($codes[2]), 'status' => 'available', 'order_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ['game_id' => $other, 'code' => Crypt::encryptString($codes[3]), 'status' => 'available', 'order_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ['game_id' => $other, 'code' => 'not-a-valid-ciphertext', 'status' => 'available', 'order_id' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    expect(Artisan::call('migrate'))->toBe(0);

    // Nothing lost.
    expect(DB::table('users')->count())->toBe(2)
        ->and(DB::table('games')->count())->toBe(2)
        ->and(DB::table('orders')->count())->toBe(3)
        ->and(DB::table('game_keys')->count())->toBe(5)
        ->and(DB::table('game_keys')->where('id', $soldKey)->value('order_id'))->toBe($delivered);

    // Backfills.
    $publicIds = DB::table('orders')->pluck('public_id');
    expect($publicIds->unique())->toHaveCount(3)
        ->and($publicIds->every(fn ($id) => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1))->toBeTrue()
        ->and(DB::table('game_keys')->whereNotNull('code_hash')->pluck('code_hash')->sort()->values()->all())
        ->toBe(collect($codes)->map(fn ($c) => KeyFingerprint::of($c))->sort()->values()->all())
        ->and(DB::table('game_keys')->where('code', 'not-a-valid-ciphertext')->value('code_hash'))->toBeNull()
        ->and(DB::table('game_keys')->where('id', $soldKey)->whereNotNull('reserved_at')->whereNotNull('sold_at')->exists())->toBeTrue()
        ->and((array) DB::table('orders')->where('id', $failed)->first(['failure_code']))->toBe(['failure_code' => 'out_of_stock'])
        ->and(DB::table('orders')->where('id', $failed)->value('failed_at'))->not->toBeNull()
        ->and(DB::table('users')->where('is_admin', true)->count())->toBe(0)
        ->and(DB::table('games')->where('currency', 'EUR')->where('is_active', true)->count())->toBe(2);

    // The upgraded data works with the new code: the legacy pending order is fulfilled.
    expect(app(FulfillOrder::class)->handle($pending))->toBe(FulfillmentOutcome::Delivered)
        ->and(DB::table('game_keys')->where('order_id', $pending)->value('status'))->toBe('sold');

    // And the upgrade is reversible without losing the original data.
    expect(Artisan::call('migrate:rollback', ['--step' => 2]))->toBe(0)
        ->and(DB::table('orders')->count())->toBe(3)
        ->and(DB::table('game_keys')->count())->toBe(5)
        ->and(DB::table('orders')->where('id', $pending)->value('status'))->toBe('delivered')
        ->and(Artisan::call('migrate'))->toBe(0);
});

it('stops before changing anything when legacy stock contains duplicate key codes', function () {
    migrateToOverlaySchema();
    $game = DB::table('games')->insertGetId(['name' => 'Dup', 'slug' => 'dup', 'platform' => 'Steam', 'price_cents' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2] as $_) {
        DB::table('game_keys')->insert(['game_id' => $game, 'code' => Crypt::encryptString('SAME-CODE-12345'), 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }
    $before = schemaSnapshot();

    expect(fn () => Artisan::call('migrate'))->toThrow(RuntimeException::class, 'Duplicate key codes');

    expect(schemaSnapshot())->toEqual($before); // no partial DDL was applied
});
