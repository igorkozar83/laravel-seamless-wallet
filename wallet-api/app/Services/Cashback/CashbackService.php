<?php

namespace App\Services\Cashback;

use App\Enums\TransactionType;
use App\Events\CashbackPaid;
use App\Models\CashbackPayout;
use App\Models\OutboxMessage;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The cashback RULES live here. Jobs, commands or an admin action only decide WHEN to call it.
 */
class CashbackService
{
    public const PERCENT = 10;

    /**
     * Pay one wallet its cashback for one week. Safe to call any number of times: it pays at most once.
     *
     * @return CashbackPayout|null the payout, or null when there is nothing to pay
     */
    public function payWallet(int $walletId, int $lossCents, string $weekStart): ?CashbackPayout
    {
        $cashbackCents = intdiv($lossCents * self::PERCENT, 100);       // integer cents, rounded down
        if ($cashbackCents === 0) {
            return null;
        }

        try {
            return DB::transaction(function () use ($walletId, $lossCents, $cashbackCents, $weekStart) {
                // the player may be betting right now: lock the wallet so no update is lost
                $wallet = Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();

                // idempotency: already paid for this week? return that payout, move no money
                if ($existing = $this->find($wallet->id, $weekStart)) {
                    return $existing;
                }

                $payout = CashbackPayout::create([
                    'wallet_id' => $wallet->id,
                    'week_start' => $weekStart,
                    'loss_cents' => $lossCents,
                    'cashback_cents' => $cashbackCents,
                ]);

                $balanceAfter = $wallet->balance_cents + $cashbackCents;
                $wallet->forceFill(['balance_cents' => $balanceAfter])->save();

                $transaction = $wallet->transactions()->create([           // the ledger says WHY the balance changed
                    'type' => TransactionType::Cashback,
                    'amount_cents' => $cashbackCents,
                    'balance_after_cents' => $balanceAfter,
                    'idempotency_key' => "cashback:{$wallet->id}:{$weekStart}",
                ]);
                OutboxMessage::recordFor($transaction);

                CashbackPaid::dispatch($payout);                           // after commit: email etc. never for a rolled-back payout

                return $payout;
            });
        } catch (UniqueConstraintViolationException) {
            // two calls raced and the other one won: its payout stands, ours was rolled back
            return $this->find($walletId, $weekStart);
        }
    }

    private function find(int $walletId, string $weekStart): ?CashbackPayout
    {
        return CashbackPayout::where(['wallet_id' => $walletId, 'week_start' => $weekStart])->first();
    }
}
