<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameKey> */
class GameKeyFactory extends Factory
{
    protected $model = GameKey::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            // Synthetic, clearly non-commercial format.
            'code' => 'DEMO-'.fake()->unique()->bothify('?????-#####-?????'),
            'status' => 'available',
        ];
    }
}
