<?php

namespace App\Jobs;

use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the provider for a round's result and credits the win. Runs on a queue worker.
 *
 * - $tries / backoff()  : the provider API will sometimes fail; retry 5 times, waiting longer each time
 * - ShouldBeUnique       : the same round can't be QUEUED twice while one is waiting
 * - Cache::lock          : the same round can't be PROCESSED by two workers at once
 * - idempotency key      : even if everything above fails, WalletService credits the win only once
 * - failed()             : runs after the last attempt — alert someone, money is involved
 */
final class SettleProviderRound implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly string $roundId, public readonly int $walletId) {}

    public function uniqueId(): string
    {
        return $this->roundId;
    }

    /** seconds to wait before retry 1, 2, 3, 4 — exponential-ish backoff */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(WalletService $wallets): void          // dependencies injected into handle()
    {
        $lock = Cache::lock("settle-round:{$this->roundId}", 30);

        if (! $lock->get()) {
            $this->release(5);                                     // another worker has it — try again in 5s

            return;
        }

        try {
            $result = Http::baseUrl(config('services.game_providers.base_url'))
                ->timeout(5)
                ->get("/rounds/{$this->roundId}")
                ->throw()                                          // 4xx/5xx → exception → the queue retries
                ->json();

            if (($result['win_cents'] ?? 0) > 0) {
                $wallets->win(Wallet::query()->findOrFail($this->walletId), $result['win_cents'], "settle-{$this->roundId}", $this->roundId);
            }
        } finally {
            $lock->release();                                      // always release, even on failure
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('Round could not be settled', ['round' => $this->roundId, 'error' => $exception->getMessage()]);
    }
}
