<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Services\Inventory\InventoryAllocator;
use App\Support\E2eGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fixtures for the k6 load tests (loadtest/k6, scripts/loadtest.sh), added on top of E2eSeeder:
 * customers load1..load200@e2e.test, a title with ample stock for the checkout burst and one
 * with exactly 10 keys for the contention test. E2E database only.
 */
class LoadTestSeeder extends Seeder
{
    public const USERS = 200;

    public function run(InventoryAllocator $inventory): void
    {
        E2eGuard::assertIsolated();

        $password = Hash::make(E2eSeeder::PASSWORD);
        $now = now();
        DB::table('users')->insert(array_map(fn (int $n) => [
            'name' => "Load Tester {$n}", 'email' => "load{$n}@e2e.test", 'password' => $password,
            'email_verified_at' => $now, 'is_admin' => false, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, self::USERS)));

        foreach ([['Load Test Arena', 'Steam', 300], ['Limited Drop', 'Xbox', 10]] as [$name, $platform, $keys]) {
            $slug = Str::slug($name);
            $game = Game::query()->forceCreate([
                'slug' => $slug, 'name' => $name, 'platform' => $platform, 'genre' => 'Action', 'price_cents' => 999,
                'currency' => 'EUR', 'description' => 'Load-test fixture.',
            ]);
            $codes = array_map(fn (int $n) => 'GVLOAD-'.strtoupper(substr(sha1("{$slug}#{$n}"), 0, 6)).'-'.strtoupper(substr(sha1("{$n}#{$slug}"), 0, 6)), range(1, $keys));
            DB::transaction(fn () => $inventory->import($game, $codes));
        }
    }
}
