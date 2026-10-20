<?php

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Provider> */
class ProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'commission' => fake()->randomElement(['0.1000', '0.1200', '0.1500']),
        ];
    }
}
