<?php

namespace Tests\Feature;

use App\Events\CashbackPaid;
use App\Jobs\Cashback\StartWeeklyCashback;
use App\Models\Wallet;
use App\Services\Cashback\CashbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WeeklyCashbackTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-10-05'; // Monday

    private function ledger(Wallet $wallet, string $type, int $cents, string $at): void
    {
        static $n = 0;
        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount_cents' => $cents,
            'balance_after_cents' => 0,
            'idempotency_key' => 'test-'.(++$n),
            'created_at' => $at,
        ]);
    }

    private function balance(Wallet $wallet): int
    {
        return (int) $wallet->fresh()->balance_cents;
    }

    public function test_it_pays_ten_percent_of_last_weeks_net_loss_exactly_once(): void
    {
        Event::fake([CashbackPaid::class]);

        $loser = Wallet::factory()->withBalance(0)->create();
        $this->ledger($loser, 'bet', -10000, '2026-10-06 12:00:00');   // lost 100 €
        $this->ledger($loser, 'win', 2000, '2026-10-07 12:00:00');     // won 20 € back -> net loss 80 €

        $winner = Wallet::factory()->withBalance(0)->create();
        $this->ledger($winner, 'bet', -1000, '2026-10-06 12:00:00');
        $this->ledger($winner, 'win', 5000, '2026-10-06 12:01:00');    // net win -> no cashback

        $otherWeeks = Wallet::factory()->withBalance(0)->create();
        $this->ledger($otherWeeks, 'bet', -9000, '2026-10-04 23:59:59'); // the Sunday BEFORE the week
        $this->ledger($otherWeeks, 'bet', -9000, '2026-10-12 00:00:00'); // the Monday AFTER the week

        StartWeeklyCashback::dispatch(self::WEEK);                       // queue is "sync" in tests
        StartWeeklyCashback::dispatch(self::WEEK);                       // retry / a second server: must not pay again

        $this->assertSame(800, $this->balance($loser));                  // 10% of 8000
        $this->assertSame(0, $this->balance($winner));
        $this->assertSame(0, $this->balance($otherWeeks));
        $this->assertSame(1, DB::table('cashback_payouts')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'cashback')->count());
        Event::assertDispatchedTimes(CashbackPaid::class, 1);
    }

    public function test_the_service_can_be_used_without_a_queue_and_pays_once(): void
    {
        $wallet = Wallet::factory()->withBalance(1000)->create();
        $service = app(CashbackService::class);

        $first = $service->payWallet($wallet->id, 5000, self::WEEK);   // e.g. support pays it manually
        $again = $service->payWallet($wallet->id, 5000, self::WEEK);

        $this->assertSame(500, $first->cashback_cents);
        $this->assertTrue($first->is($again));                           // the same payout comes back
        $this->assertSame(1500, $this->balance($wallet));               // paid once
        $this->assertNull($service->payWallet($wallet->id, 5, self::WEEK));  // 10% of 5 cents rounds down to 0: nothing to pay
    }

    public function test_the_schedule_pays_for_the_week_before_the_day_it_runs(): void
    {
        Queue::fake();

        // the scheduler loads its task list once, then keeps running for weeks
        $this->travelTo('2026-10-19 03:00:00');
        $this->artisan('schedule:list')->assertSuccessful();

        $this->travelTo('2026-11-02 03:00:00');
        $this->artisan('schedule:test', ['--name' => 'weekly-cashback'])->assertSuccessful();

        Queue::assertPushed(StartWeeklyCashback::class, fn ($job) => $job->weekStart === '2026-10-26');
    }
}
