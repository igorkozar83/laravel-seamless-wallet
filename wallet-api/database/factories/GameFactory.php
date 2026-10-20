<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'title' => ucwords(fake()->words(3, true)),
            'rtp' => fake()->randomFloat(2, 94, 98),
            'is_active' => true,
        ];
    }
}
