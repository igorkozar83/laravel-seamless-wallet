<?php

namespace App\Jobs\Cashback;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of the weekly cashback: find who lost money last week and hand out one small job per wallet.
 * It never touches balances itself, so it is short and harmless to run twice.
 */
class StartWeeklyCashback implements ShouldQueue
{
    use Queueable;

    /** @param string $weekStart Monday of the week to pay for, e.g. '2026-10-05' (a FIXED period, not "the last 7 days") */
    public function __construct(public string $weekStart) {}

    public function handle(): void
    {
        $from = CarbonImmutable::parse($this->weekStart)->startOfDay();
        $to = $from->addWeek();                                         // [Monday 00:00, next Monday 00:00)

        // The net result per wallet is ONE aggregate query in the database, not a PHP loop over millions of rows.
        // Ledger amounts are signed: bets are negative, wins and rollbacks positive, so SUM < 0 means a net loss.
        DB::table('wallet_transactions')
            ->select('wallet_id', DB::raw('SUM(amount_cents) as net_cents'))
            ->whereIn('type', ['bet', 'win', 'rollback'])
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->groupBy('wallet_id')
            ->havingRaw('SUM(amount_cents) < 0')
            ->chunkById(1000, function ($rows) {                       // 1000 wallets at a time, never all in memory
                foreach ($rows as $row) {
                    PayCashback::dispatch((int) $row->wallet_id, -(int) $row->net_cents, $this->weekStart);
                }
            }, 'wallet_id');
    }
}
