<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

/** Found automatically: App\Models\Wallet → App\Policies\WalletPolicy. */
class WalletPolicy
{
    public function view(User $user, Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id;      // you can only see your own wallet
    }
}
