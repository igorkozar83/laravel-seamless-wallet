<?php

namespace App\Jobs\Cashback;

use App\Services\Cashback\CashbackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Thin wrapper: "run CashbackService::payWallet in the background, with retries". No business rules here.
 */
class PayCashback implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $walletId, public int $lossCents, public string $weekStart) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(CashbackService $cashback): void   // method injection: the container builds the service
    {
        $cashback->payWallet($this->walletId, $this->lossCents, $this->weekStart);
    }
}
