<?php

namespace App\Listeners;

use App\Events\WinCredited;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Discovered automatically by its type-hint (WinCredited). ShouldQueue = runs on a worker,
 * so the provider's HTTP call isn't slowed down by compliance checks.
 */
class FlagBigWin implements ShouldQueue
{
    public const THRESHOLD_CENTS = 100_000;          // 1,000 EUR

    public function handle(WinCredited $event): void
    {
        if ($event->transaction->amount_cents < self::THRESHOLD_CENTS) {
            return;
        }

        Cache::increment('compliance:big-wins');
        Log::info('Big win flagged for review', ['transaction' => $event->transaction->id]);
    }
}
