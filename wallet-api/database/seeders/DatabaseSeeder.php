<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * One demo player with a funded wallet, so the gateway examples in the README work
     * straight after `docker compose up`. The deposit goes through WalletService, so the
     * ledger and the balance agree from the first row.
     */
    public function run(WalletService $wallets): void
    {
        $player = User::query()->firstOrCreate(
            ['email' => 'player@example.com'],
            ['name' => 'Demo Player', 'password' => bcrypt('password')],
        );

        $wallet = Wallet::query()->firstOrCreate(['user_id' => $player->id], ['currency' => 'EUR']);

        $wallets->deposit($wallet, 10_000, 'seed-deposit-'.$player->id);   // €100.00, idempotent on re-seed
    }
}
