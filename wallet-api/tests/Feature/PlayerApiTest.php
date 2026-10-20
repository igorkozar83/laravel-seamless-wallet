<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Provider;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Topic: Sanctum auth, policies (authorization), cursor pagination, eager loading in an endpoint. */
class PlayerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_see_transactions(): void
    {
        $wallet = Wallet::factory()->create();

        $this->getJson("/api/wallets/{$wallet->id}/transactions")->assertUnauthorized();   // 401: who are you?
    }

    public function test_players_cannot_see_someone_elses_wallet(): void
    {
        $someoneElses = Wallet::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/wallets/{$someoneElses->id}/transactions")->assertForbidden(); // 403: not yours
    }

    public function test_transactions_are_cursor_paginated_newest_first(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $service = app(WalletService::class);
        foreach (range(1, 25) as $i) {
            $service->deposit($wallet, 100, "deposit-{$i}");
        }
        Sanctum::actingAs($user);

        $page1 = $this->getJson("/api/wallets/{$wallet->id}/transactions")->assertOk();
        $this->assertCount(20, $page1->json('data'));
        $this->assertSame(2500, $page1->json('data.0.balance_after_cents'));             // newest first

        $page2 = $this->getJson($page1->json('links.next'))->assertOk();                  // ?cursor=…
        $this->assertCount(5, $page2->json('data'));
        $this->assertNull($page2->json('links.next'));
    }

    public function test_the_games_endpoint_does_not_have_an_n_plus_one_problem(): void
    {
        Provider::factory(3)->has(Game::factory(10))->create();

        DB::enableQueryLog();
        $this->getJson('/api/games')->assertOk()->assertJsonCount(30, 'data')->assertJsonStructure(['data' => [['id', 'title', 'rtp', 'provider']]]);
        $queries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(2, $queries, "Expected eager loading, got {$queries} queries");
    }
}
