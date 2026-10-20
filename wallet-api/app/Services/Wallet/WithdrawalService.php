<?php

namespace App\Services\Wallet;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFunds;
use App\Models\OutboxMessage;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    /**
     * Create a new class instance.
     */
    public function withdraw(Wallet $wallet, int $amount_cents, string $key): Withdrawal
    {

        $withdrawal = Withdrawal::where(['wallet_id' => $wallet->id, 'idempotency_key' => $key])->first();

        if ($withdrawal) {

            if ($amount_cents !== $withdrawal->amount_cents) {
                abort(422, 'Idempotency-Key was already used with a different amount');
            }

            return $withdrawal;

        }

        try {

            $withdrawal = DB::transaction(function () use ($wallet, $key, $amount_cents) {

                $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

                $withdrawal = Withdrawal::where(['wallet_id' => $wallet->id, 'idempotency_key' => $key])->first();

                if ($withdrawal) {

                    if ($amount_cents !== $withdrawal->amount_cents) {
                        abort(422, 'Idempotency-Key was already used with a different amount');
                    }

                    return $withdrawal;
                }

                $balance = $wallet->balance_cents;
                $new_balance = $balance - $amount_cents;

                if ($balance < $amount_cents) {
                    throw new InsufficientFunds($balance, $amount_cents);
                }

                // wallets withdrawals

                $wallet->forceFill([
                    'balance_cents' => $new_balance,
                ])->save();

                // the ledger row keeps the balance reconcilable with its history, the outbox row tells everyone else
                $transaction = $wallet->transactions()->create([
                    'type' => TransactionType::Withdrawal,
                    'amount_cents' => -$amount_cents,
                    'balance_after_cents' => $new_balance,
                    'idempotency_key' => "withdrawal:{$wallet->id}:{$key}",
                ]);
                OutboxMessage::recordFor($transaction);

                // if we have row with same key and wallet id it will throw exception as this is unique, so we don't have 2 same withdraws
                return Withdrawal::create([
                    'wallet_id' => $wallet->id,
                    'amount_cents' => $amount_cents,
                    'balance_after_cents' => $new_balance,
                    'idempotency_key' => $key,
                ]);

            });

        } catch (UniqueConstraintViolationException) {
            return Withdrawal::where(['wallet_id' => $wallet->id, 'idempotency_key' => $key])->firstOrFail();
        }

        return $withdrawal;

    }
}
