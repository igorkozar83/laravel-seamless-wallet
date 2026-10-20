<?php

namespace Tests\Feature;

use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Idempotent withdrawal endpoint: the Idempotency-Key header makes a retried request safe.
 * Run: php artisan test --filter=WithdrawalApi
 */
class WithdrawalApiTest extends TestCase
{
    use RefreshDatabase;

    private function playerWithBalance(int $cents): User
    {
        $user = User::factory()->create();
        Wallet::factory()->withBalance($cents)->create(['user_id' => $user->id]);

        return $user;
    }

    private function withdraw(User $user, ?string $key, array $body): TestResponse
    {
        Sanctum::actingAs($user);
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];

        return $this->postJson('/api/withdrawals', $body, $headers);
    }

    private function balanceOf(User $user): int
    {
        return (int) $user->wallet()->value('balance_cents');
    }

    public function test_a_withdrawal_takes_the_money_and_returns_201(): void
    {
        $ana = $this->playerWithBalance(10000);

        $this->withdraw($ana, 'k-1', ['amount_cents' => 3000])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 3000)
            ->assertJsonPath('data.balance_cents', 7000);

        $this->assertSame(7000, $this->balanceOf($ana));
        $this->assertSame(1, DB::table('withdrawals')->count());

        // the ledger still explains the balance, and the rest of the system hears about it
        $this->assertDatabaseHas('wallet_transactions', ['type' => 'withdrawal', 'amount_cents' => -3000, 'balance_after_cents' => 7000]);
        $this->assertSame(1, OutboxMessage::where('topic', 'wallet.withdrawal')->count());
    }

    public function test_a_retry_with_the_same_key_returns_the_same_body_and_moves_no_money(): void
    {
        $ana = $this->playerWithBalance(10000);

        $first = $this->withdraw($ana, 'k-1', ['amount_cents' => 3000])->assertCreated();
        $retry = $this->withdraw($ana, 'k-1', ['amount_cents' => 3000])->assertOk(); // 200, not 201

        $this->assertSame($first->json('data'), $retry->json('data'));
        $this->assertSame(7000, $this->balanceOf($ana));
        $this->assertSame(1, DB::table('withdrawals')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'withdrawal')->count());
    }

    public function test_the_same_key_with_a_different_amount_is_rejected(): void
    {
        $ana = $this->playerWithBalance(10000);

        $this->withdraw($ana, 'k-1', ['amount_cents' => 3000])->assertCreated();
        $this->withdraw($ana, 'k-1', ['amount_cents' => 9000])->assertUnprocessable();

        $this->assertSame(7000, $this->balanceOf($ana));
    }

    public function test_keys_belong_to_one_player_only(): void
    {
        $ana = $this->playerWithBalance(10000);
        $marko = $this->playerWithBalance(10000);

        $this->withdraw($ana, 'k-1', ['amount_cents' => 3000])->assertCreated();
        $this->withdraw($marko, 'k-1', ['amount_cents' => 1000])->assertCreated(); // his own new withdrawal

        $this->assertSame(7000, $this->balanceOf($ana));
        $this->assertSame(9000, $this->balanceOf($marko));
    }

    public function test_insufficient_funds_returns_422_and_moves_nothing(): void
    {
        $ana = $this->playerWithBalance(5000);

        $this->withdraw($ana, 'k-1', ['amount_cents' => 9000])->assertUnprocessable();

        $this->assertSame(5000, $this->balanceOf($ana));
        $this->assertSame(0, DB::table('withdrawals')->count());
    }

    public function test_the_key_header_is_required_and_the_amount_is_validated(): void
    {
        $ana = $this->playerWithBalance(10000);

        $this->withdraw($ana, null, ['amount_cents' => 3000])->assertStatus(400);
        $this->withdraw($ana, 'k-1', ['amount_cents' => 0])->assertJsonValidationErrors('amount_cents');
        $this->withdraw($ana, 'k-2', ['amount_cents' => 'lots'])->assertJsonValidationErrors('amount_cents');

        $this->assertSame(10000, $this->balanceOf($ana));
    }

    public function test_only_logged_in_players_can_withdraw(): void
    {
        $this->postJson('/api/withdrawals', ['amount_cents' => 3000], ['Idempotency-Key' => 'k-1'])
            ->assertUnauthorized();
    }
}
