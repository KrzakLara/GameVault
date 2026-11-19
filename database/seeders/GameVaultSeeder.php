<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\GameKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GameVaultSeeder extends Seeder
{
    public function run(): void
    {
        $titles = [
            ['Starlight Odyssey','Steam',2999],['Neon Drift','Steam',1999],['Rune Keepers','Steam',3499],
            ['Cyber Arena','PlayStation',3999],['Crystal Horizon','Xbox',2499],['Shadow Protocol','Steam',4599],
            ['Solar Run','Xbox',1499],['Arcane Frontier','PlayStation',4499],['Velocity Zero','Steam',1299],
            ['Nova Quest','Xbox',2799],['Echo Valley','Steam',1699],['Titan Forge','PlayStation',4999],
            ['Nightfall City','Steam',2199],['Pixel Raiders','Xbox',999],['Dream Circuit','PlayStation',2999],
            ['Stormbound Legends','Steam',3999],['Glitch Runner','Steam',1199],['Moonbase 7','Xbox',2299],
            ['Hollow Citadel','PlayStation',3399],['Parallel Worlds','Steam',2699],
        ];
        foreach ($titles as [$name,$platform,$price]) {
            $game = Game::firstOrCreate(['slug'=>Str::slug($name)], [
                'name'=>$name,'platform'=>$platform,'price_cents'=>$price,
                'description'=>'Discover '.$name.', an original fictional game in our demo catalogue.',
            ]);
            if ($game->keys()->exists()) continue;
            for ($i=0; $i<5; $i++) {
                GameKey::create(['game_id'=>$game->id,'code'=>implode('-', [Str::upper(Str::random(5)),Str::upper(Str::random(5)),Str::upper(Str::random(5))]),'status'=>'available']);
            }
        }
    }
}
