<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        $name = Str::title(implode(' ', (array) fake()->unique()->words(3)));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'platform' => 'Steam',
            'genre' => 'Action',
            'price_cents' => 1999,
            'currency' => 'EUR',
            'description' => 'Fictional demo game.',
            'is_active' => true,
            'is_featured' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
