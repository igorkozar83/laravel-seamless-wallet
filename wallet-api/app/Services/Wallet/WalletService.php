<?php

namespace App\Services\Wallet;

use App\Enums\TransactionType;
use App\Events\BetPlaced;
use App\Events\WinCredited;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\TransactionIdConflict;
use App\Models\OutboxMessage;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ✅ The correct wallet: transaction + row lock (pessimistic locking) + idempotency + outbox.
 *
 * - DB transaction : balance update and ledger row are saved together, or not at all
 * - lockForUpdate  : a second request for the same wallet WAITS until the first commits
 * - idempotency    : the provider's transaction id is stored once; a retry returns the original result
 * - outbox         : an event row is written in the same transaction, so other services hear about
 *                    every committed movement — and never about a rolled-back one
 */
final class WalletService
{
    public function deposit(Wallet $wallet, int $amountCents, string $idempotencyKey): WalletTransaction
    {
        return $this->apply($wallet->id, TransactionType::Deposit, $this->positive($amountCents), $idempotencyKey, null);
    }

    public function bet(Wallet $wallet, int $amountCents, string $idempotencyKey, ?string $roundId = null): WalletTransaction
    {
        return $this->apply($wallet->id, TransactionType::Bet, -$this->positive($amountCents), $idempotencyKey, $roundId);
    }

    public function win(Wallet $wallet, int $amountCents, string $idempotencyKey, ?string $roundId = null): WalletTransaction
    {
        return $this->apply($wallet->id, TransactionType::Win, $this->positive($amountCents), $idempotencyKey, $roundId);
    }

    private function apply(int $walletId, TransactionType $type, int $signedCents, string $key, ?string $roundId): WalletTransaction
    {
        // fast path: this exact request was already processed → return the same answer, move no money
        if ($existing = $this->findByKey($key)) {
            return $this->replay($existing, $walletId, $type, $signedCents);
        }

        $transaction = DB::transaction(function () use ($walletId, $type, $signedCents, $key, $roundId) {
            // SELECT ... FOR UPDATE: other requests for this wallet block here until we commit
            $wallet = Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();

            // check again INSIDE the lock: two identical requests may have arrived at the same moment
            if ($existing = $this->findByKey($key)) {
                return $this->replay($existing, $walletId, $type, $signedCents);
            }

            $newBalance = $wallet->balance_cents + $signedCents;
            if ($newBalance < 0) {
                throw new InsufficientFunds($wallet->balance_cents, abs($signedCents));
            }

            $wallet->forceFill(['balance_cents' => $newBalance])->save();

            $transaction = $wallet->transactions()->create([
                'type' => $type,
                'amount_cents' => $signedCents,
                'balance_after_cents' => $newBalance,
                'idempotency_key' => $key,
                'round_id' => $roundId,
            ]);

            // same transaction as the money: the event exists if and only if the movement committed
            OutboxMessage::recordFor($transaction);

            return $transaction;
        });

        // dispatched after commit (see the events), and only for NEW transactions, never for retries
        if ($transaction->wasRecentlyCreated) {
            match ($type) {
                TransactionType::Bet => BetPlaced::dispatch($transaction),
                TransactionType::Win => WinCredited::dispatch($transaction),
                default => null,
            };
        }

        return $transaction;
    }

    /** A retry must be the SAME request. Anything else would hand one player another player's transaction. */
    private function replay(WalletTransaction $existing, int $walletId, TransactionType $type, int $signedCents): WalletTransaction
    {
        if ($existing->wallet_id !== $walletId || $existing->type !== $type || $existing->amount_cents !== $signedCents) {
            throw new TransactionIdConflict($existing->idempotency_key);
        }

        return $existing;
    }

    /** HTTP validation already demands >= 1, but a negative bet would CREDIT the wallet, so the service checks too. */
    private function positive(int $cents): int
    {
        if ($cents <= 0) {
            throw new InvalidArgumentException("Amount must be positive, got {$cents} cents");
        }

        return $cents;
    }

    private function findByKey(string $key): ?WalletTransaction
    {
        return WalletTransaction::query()->where('idempotency_key', $key)->first();
    }
}
