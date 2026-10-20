<?php

namespace App\Events;

use App\Models\CashbackPayout;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Listeners (e.g. a queued "send the player an email") only run if the payout really committed. */
final class CashbackPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CashbackPayout $payout) {}
}
