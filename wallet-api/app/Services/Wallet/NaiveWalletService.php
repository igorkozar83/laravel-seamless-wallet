<?php

namespace App\Services\Wallet;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFunds;
use App\Models\Wallet;

/**
 * ❌ The broken wallet — what many first versions look like.
 *
 * read balance → compute in PHP → write balance back.
 * Two requests that read at the same time both write "their" result, and one write is lost
 * (a "lost update"). No transaction, no lock, no idempotency: a provider retry charges twice.
 */
final class NaiveWalletService
{
    public function bet(Wallet $wallet, int $amountCents, string $idempotencyKey, ?string $roundId = null): void
    {
        $fresh = Wallet::query()->findOrFail($wallet->id);           // 1. read

        usleep(random_int(1_000, 5_000));                             // the time real code spends in between

        if ($fresh->balance_cents < $amountCents) {                   // 2. check on a value that may be stale
            throw new InsufficientFunds($fresh->balance_cents, $amountCents);
        }

        $newBalance = $fresh->balance_cents - $amountCents;
        $fresh->forceFill(['balance_cents' => $newBalance])->save(); // 3. write — overwrites anyone in between

        $fresh->transactions()->create([
            'type' => TransactionType::Bet,
            'amount_cents' => -$amountCents,
            'balance_after_cents' => $newBalance,
            'idempotency_key' => $idempotencyKey.'-'.bin2hex(random_bytes(4)), // no real idempotency
            'round_id' => $roundId,
        ]);
    }
}
