<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Wallet> */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'currency' => 'EUR'];
    }

    /** Balance is not fillable, so it's set directly after creating the model. */
    public function withBalance(int $cents): static
    {
        return $this->afterCreating(function ($wallet) use ($cents) {
            $wallet->forceFill(['balance_cents' => $cents])->save();
        });
    }
}
