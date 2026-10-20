<?php

namespace App\Events;

use App\Models\WalletTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

// ShouldDispatchAfterCommit: listeners run only if the DB transaction commits.
// Without it, a listener could react to a bet that was rolled back.
final class BetPlaced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly WalletTransaction $transaction) {}
}
