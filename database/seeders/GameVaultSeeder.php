<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Services\Inventory\InventoryAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fictional catalogue with synthetic keys. Idempotent: re-running only adds games that do not
 * exist yet. Keys go through the allocator so they get fingerprints and "imported" movements.
 */
class GameVaultSeeder extends Seeder
{
    /** Fictional catalogue: name, platform, genre, price in cents. Also used by E2eSeeder. */
    public const TITLES = [
        ['Starlight Odyssey', 'Steam', 'Adventure', 2999], ['Neon Drift', 'Steam', 'Racing', 1999], ['Rune Keepers', 'Steam', 'RPG', 3499],
        ['Cyber Arena', 'PlayStation', 'Shooter', 3999], ['Crystal Horizon', 'Xbox', 'Adventure', 2499], ['Shadow Protocol', 'Steam', 'Action', 4599],
        ['Solar Run', 'Xbox', 'Racing', 1499], ['Arcane Frontier', 'PlayStation', 'RPG', 4499], ['Velocity Zero', 'Steam', 'Racing', 1299],
        ['Nova Quest', 'Xbox', 'RPG', 2799], ['Echo Valley', 'Steam', 'Indie', 1699], ['Titan Forge', 'PlayStation', 'Strategy', 4999],
        ['Nightfall City', 'Steam', 'Action', 2199], ['Pixel Raiders', 'Xbox', 'Indie', 999], ['Dream Circuit', 'PlayStation', 'Indie', 2999],
        ['Stormbound Legends', 'Steam', 'Strategy', 3999], ['Glitch Runner', 'Steam', 'Action', 1199], ['Moonbase 7', 'Xbox', 'Strategy', 2299],
        ['Hollow Citadel', 'PlayStation', 'Adventure', 3399], ['Parallel Worlds', 'Steam', 'Adventure', 2699],
    ];

    public function run(InventoryAllocator $inventory): void
    {
        foreach (self::TITLES as $i => [$name, $platform, $genre, $price]) {
            $game = Game::firstOrCreate(['slug' => Str::slug($name)], [
                'name' => $name, 'platform' => $platform, 'genre' => $genre, 'price_cents' => $price, 'currency' => 'EUR',
                'is_featured' => $i < 4,
                'description' => 'Discover '.$name.', an original fictional game in our demo catalogue.',
            ]);

            if ($game->keys()->exists()) {
                continue;
            }

            // Synthetic, clearly non-commercial codes. A couple of titles start with one key so
            // the "last key" race is easy to try by hand.
            $codes = array_map(fn () => 'DEMO-'.Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)), range(1, $i % 7 === 0 ? 1 : 5));
            DB::transaction(fn () => $inventory->import($game, $codes));
        }
    }
}
