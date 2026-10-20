<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFunds;
use App\Models\OutboxMessage;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Transactional outbox: events are written with the money, published to Redis by a relay. */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM = 'wallet-events-testing';

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);
        config(['wallet.events_stream' => self::STREAM]);
    }

    public function test_a_committed_bet_writes_exactly_one_outbox_message(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $tx = $this->wallets->bet($wallet, 1000, 'provider-tx-1', 'round-1');

        $message = OutboxMessage::sole();
        $this->assertSame('wallet.bet', $message->topic);
        $this->assertSame($tx->id, $message->payload['transaction_id']);
        $this->assertSame(-1000, $message->payload['amount_cents']);
        $this->assertSame('round-1', $message->payload['round_id']);
        $this->assertNull($message->published_at);
    }

    public function test_a_rejected_bet_writes_no_outbox_message(): void
    {
        $wallet = Wallet::factory()->withBalance(500)->create();

        try {
            $this->wallets->bet($wallet, 1000, 'provider-tx-1', 'round-1');
            $this->fail('Expected InsufficientFunds');
        } catch (InsufficientFunds) {
            // expected: the transaction rolled back, so the event must not exist either
        }

        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_a_retried_transaction_writes_no_second_message(): void
    {
        $wallet = Wallet::factory()->withBalance(5000)->create();

        $this->wallets->bet($wallet, 1000, 'provider-tx-1', 'round-1');
        $this->wallets->bet($wallet, 1000, 'provider-tx-1', 'round-1');

        $this->assertSame(1, OutboxMessage::count());
    }

    public function test_the_relay_publishes_each_message_once_and_marks_it(): void
    {
        $redis = Redis::connection('events');
        $redis->del(self::STREAM);
        $wallet = Wallet::factory()->withBalance(5000)->create();
        $this->wallets->bet($wallet, 1000, 'provider-tx-1', 'round-1');
        $this->wallets->win($wallet, 2500, 'provider-tx-2', 'round-1');

        $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
        $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();   // nothing left to publish

        $this->assertSame(0, OutboxMessage::whereNull('published_at')->count());

        // read with a plain client, the way a Node or Go consumer would: the stream must exist under
        // its exact name, with no Laravel key prefix in front of it
        $raw = new \Redis;
        $raw->connect(config('database.redis.events.host'), (int) config('database.redis.events.port'));
        $entries = array_values($raw->xRange(self::STREAM, '-', '+'));
        $this->assertCount(2, $entries);
        $this->assertSame(['wallet.bet', 'wallet.win'], array_column($entries, 'topic'));
        $this->assertSame(2500, json_decode($entries[1]['payload'], true)['amount_cents']);
    }
}
