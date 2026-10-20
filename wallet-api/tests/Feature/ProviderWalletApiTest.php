<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Topic: middleware (HMAC signature), Form Request validation, API Resource, idempotent endpoint, central error handling. */
class ProviderWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_bet_is_accepted(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $this->signedPost('/api/provider/bet', $this->payload($wallet))
            ->assertCreated()
            ->assertJsonPath('data.type', 'bet')
            ->assertJsonPath('data.amount_cents', -1000)
            ->assertJsonPath('data.balance_after_cents', 4000)
            ->assertJsonMissingPath('data.idempotency_key');          // internal fields never leak
    }

    public function test_a_retried_bet_returns_the_original_result_without_charging_again(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $first = $this->signedPost('/api/provider/bet', $this->payload($wallet))->assertCreated();
        $retry = $this->signedPost('/api/provider/bet', $this->payload($wallet))->assertOk();   // 200, not 201

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame(4000, $wallet->fresh()->balance_cents);
    }

    public function test_requests_without_a_valid_signature_are_rejected(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $this->postJson('/api/provider/bet', $this->payload($wallet))->assertUnauthorized();          // no signature
        $this->signedPost('/api/provider/bet', $this->payload($wallet), secret: 'wrong')->assertUnauthorized();
        $this->assertSame(5000, $wallet->fresh()->balance_cents);
    }

    public function test_a_captured_request_cannot_be_replayed_later(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();
        $timestamp = now()->timestamp;

        $this->travel(10)->minutes();                                   // time travel: 10 minutes later…
        $this->signedPost('/api/provider/bet', $this->payload($wallet), timestamp: $timestamp)
            ->assertUnauthorized();                                     // …the old signature is refused
    }

    public function test_invalid_input_returns_422_with_field_errors(): void
    {
        $this->signedPost('/api/provider/bet', ['player_id' => 999, 'amount_cents' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id', 'amount_cents', 'transaction_id', 'round_id']);
    }

    public function test_a_transaction_id_cannot_imitate_an_internal_ledger_key(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $this->signedPost('/api/provider/bet', ['transaction_id' => "withdrawal:{$wallet->id}:k-1"] + $this->payload($wallet))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction_id');
    }

    public function test_insufficient_funds_is_turned_into_a_clean_json_error(): void
    {
        $wallet = Wallet::factory()->withBalance(500)->create();

        $this->signedPost('/api/provider/bet', $this->payload($wallet))
            ->assertUnprocessable()
            ->assertExactJson(['error' => 'INSUFFICIENT_FUNDS', 'balance_cents' => 500, 'requested_cents' => 1000]);
    }

    private function payload(Wallet $wallet): array
    {
        return ['player_id' => $wallet->user_id, 'amount_cents' => 1000, 'transaction_id' => 'tx-1', 'round_id' => 'round-1'];
    }

    /** Sends the request the way a provider would: raw JSON body + HMAC headers. */
    private function signedPost(string $uri, array $payload, string $secret = 'local-dev-secret', ?int $timestamp = null): TestResponse
    {
        $body = json_encode($payload);
        $timestamp ??= now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => $signature,
        ], content: $body);
    }
}
