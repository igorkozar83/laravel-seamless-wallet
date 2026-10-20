<?php

namespace Tests\Feature;

use App\Events\WinCredited;
use App\Jobs\SettleProviderRound;
use App\Listeners\FlagBigWin;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Topic: jobs, retries/backoff, unique jobs, Cache::lock, Http::fake, queued listeners. */
class QueueAndEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settling_a_round_is_pushed_to_the_queue(): void
    {
        Queue::fake();                                               // nothing really runs, we only check the push

        SettleProviderRound::dispatch('round-1', 1);

        Queue::assertPushed(SettleProviderRound::class, fn ($job) => $job->roundId === 'round-1');
    }

    public function test_the_job_is_configured_to_retry_with_backoff_and_to_be_unique_per_round(): void
    {
        $job = new SettleProviderRound('round-7', 1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([10, 30, 60, 120], $job->backoff());
        $this->assertSame('round-7', $job->uniqueId());
    }

    public function test_the_job_credits_the_win_once_even_if_it_runs_twice(): void
    {
        Http::fake(['*/rounds/round-1' => Http::response(['win_cents' => 2500])]);   // no real HTTP call
        $wallet = Wallet::factory()->withBalance(0)->create();
        $job = new SettleProviderRound('round-1', $wallet->id);

        $job->handle(app(WalletService::class));
        $job->handle(app(WalletService::class));                     // e.g. a retry after a timeout

        $this->assertSame(2500, $wallet->fresh()->balance_cents);
        $this->assertSame(1, WalletTransaction::where('round_id', 'round-1')->count());
        Http::assertSentCount(2);
    }

    public function test_a_provider_error_makes_the_job_fail_so_the_queue_retries_it(): void
    {
        Http::fake(['*' => Http::response(status: 503)]);
        $wallet = Wallet::factory()->create();

        $this->expectException(RequestException::class);
        (new SettleProviderRound('round-1', $wallet->id))->handle(app(WalletService::class));
    }

    public function test_a_round_already_locked_by_another_worker_is_released_back_to_the_queue(): void
    {
        $wallet = Wallet::factory()->create();
        Cache::lock('settle-round:round-1', 30)->get();             // another worker holds the lock

        $job = (new SettleProviderRound('round-1', $wallet->id))->withFakeQueueInteractions();
        $job->handle(app(WalletService::class));

        $job->assertReleased(delay: 5);
        Http::assertNothingSent();
    }

    public function test_the_big_win_listener_is_wired_up_and_queued(): void
    {
        Event::fake();                                               // assertListening exists only on the fake
        Event::assertListening(WinCredited::class, FlagBigWin::class);   // found by auto-discovery
        $this->assertContains(ShouldQueue::class, class_implements(FlagBigWin::class));
    }

    public function test_big_wins_are_flagged_and_small_ones_are_not(): void
    {
        $wallet = Wallet::factory()->withBalance(0)->create();
        $service = app(WalletService::class);
        $service->win($wallet, 500, 'small-win');                    // below the threshold
        $service->win($wallet, 150_000, 'big-win');                  // 1,500 EUR

        $this->assertSame(1, Cache::get('compliance:big-wins'));
    }
}
