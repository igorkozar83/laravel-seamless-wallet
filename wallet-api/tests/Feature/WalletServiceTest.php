<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Events\BetPlaced;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\TransactionIdConflict;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/** Topic: DB transactions, ledger, idempotency, events after commit. */
class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);      // resolved from the container, like in the app
    }

    public function test_a_bet_moves_money_and_writes_one_ledger_row(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $tx = $this->wallets->bet($wallet, 1000, 'provider-tx-1', 'round-1');

        $this->assertSame(4000, $wallet->fresh()->balance_cents);
        $this->assertSame(TransactionType::Bet, $tx->type);
        $this->assertSame(-1000, $tx->amount_cents);
        $this->assertSame(4000, $tx->balance_after_cents);
    }

    public function test_the_same_provider_transaction_is_charged_only_once(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $first = $this->wallets->bet($wallet, 1000, 'provider-tx-1');
        $retry = $this->wallets->bet($wallet, 1000, 'provider-tx-1');   // the provider timed out and retried

        $this->assertSame($first->id, $retry->id);                      // the original answer, again
        $this->assertSame(4000, $wallet->fresh()->balance_cents);      // charged once, not twice
        $this->assertSame(1, WalletTransaction::count());
    }

    public function test_a_transaction_id_reused_by_another_player_is_a_conflict_not_a_retry(): void
    {
        $ana = Wallet::factory()->withBalance(5000)->create();
        $marko = Wallet::factory()->withBalance(5000)->create();
        $this->wallets->bet($ana, 1000, 'provider-tx-1');

        $this->expectException(TransactionIdConflict::class);   // never hand Marko Ana's transaction and balance
        $this->wallets->bet($marko, 1000, 'provider-tx-1');
    }

    public function test_a_transaction_id_reused_with_another_amount_is_a_conflict(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();
        $this->wallets->bet($wallet, 1000, 'provider-tx-1');

        try {
            $this->wallets->bet($wallet, 2500, 'provider-tx-1');
            $this->fail('Expected TransactionIdConflict');
        } catch (TransactionIdConflict) {
            $this->assertSame(4000, $wallet->fresh()->balance_cents);
        }
    }

    public function test_a_negative_bet_is_refused_instead_of_crediting_the_wallet(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->wallets->bet($wallet, -1000, 'provider-tx-1');
    }

    public function test_a_bet_larger_than_the_balance_is_rejected_and_nothing_changes(): void
    {
        $wallet = Wallet::factory()->withBalance(500)->create();

        try {
            $this->wallets->bet($wallet, 1000, 'provider-tx-1');
            $this->fail('Expected InsufficientFunds');
        } catch (InsufficientFunds $e) {
            $this->assertSame(500, $e->balanceCents);
        }

        $this->assertSame(500, $wallet->fresh()->balance_cents);
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_transaction_is_all_or_nothing(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        try {
            DB::transaction(function () use ($wallet) {
                $wallet->forceFill(['balance_cents' => 0])->save();             // step 1 succeeds…
                throw new RuntimeException('ledger insert failed');            // …step 2 fails
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(5000, $wallet->fresh()->balance_cents);               // step 1 was rolled back too
    }

    public function test_events_fire_for_new_bets_but_not_for_retries(): void
    {
        Event::fake([BetPlaced::class]);
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $this->wallets->bet($wallet, 1000, 'provider-tx-1');
        $this->wallets->bet($wallet, 1000, 'provider-tx-1');                     // retry

        Event::assertDispatchedTimes(BetPlaced::class, 1);
    }
}
