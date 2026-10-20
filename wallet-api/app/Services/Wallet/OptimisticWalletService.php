<?php

namespace App\Services\Wallet;

use App\Enums\TransactionType;
use App\Exceptions\InsufficientFunds;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ✅ Optimistic locking: no lock is held. Each write says "only if nobody changed it since I read it"
 * (WHERE version = ?). If someone did, 0 rows are updated → re-read and try again.
 *
 * Good when conflicts are rare. Under heavy contention on one row, pessimistic locking wastes less work.
 */
final class OptimisticWalletService
{
    public function __construct(private int $maxAttempts = 50) {}

    public function bet(Wallet $wallet, int $amountCents, string $idempotencyKey, ?string $roundId = null): void
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $fresh = Wallet::query()->findOrFail($wallet->id);
            if ($fresh->balance_cents < $amountCents) {
                throw new InsufficientFunds($fresh->balance_cents, $amountCents);
            }

            $saved = DB::transaction(function () use ($fresh, $amountCents, $idempotencyKey, $roundId) {
                $updated = DB::table('wallets')
                    ->where('id', $fresh->id)
                    ->where('version', $fresh->version)                     // the optimistic check
                    ->update([
                        'balance_cents' => $fresh->balance_cents - $amountCents,
                        'version' => $fresh->version + 1,
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    return false;                                            // someone was faster → retry
                }

                $fresh->transactions()->create([
                    'type' => TransactionType::Bet,
                    'amount_cents' => -$amountCents,
                    'balance_after_cents' => $fresh->balance_cents - $amountCents,
                    'idempotency_key' => $idempotencyKey,
                    'round_id' => $roundId,
                ]);

                return true;
            });

            if ($saved) {
                return;
            }

            usleep(random_int(100, 1_000));                                  // small jitter before retrying
        }

        throw new RuntimeException("Gave up after {$this->maxAttempts} attempts");
    }
}
