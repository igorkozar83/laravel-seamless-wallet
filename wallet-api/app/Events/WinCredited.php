<?php

namespace App\Events;

use App\Models\WalletTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class WinCredited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly WalletTransaction $transaction) {}
}
