<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientFunds;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\NaiveWalletService;
use App\Services\Wallet\OptimisticWalletService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 10 real OS processes bet on the SAME wallet at the same time.
 *
 *   php artisan demo:race naive         ❌ lost updates: money appears or disappears
 *   php artisan demo:race pessimistic   ✅ lockForUpdate: exactly right
 *   php artisan demo:race optimistic    ✅ version column + retry: exactly right
 */
class RaceDemo extends Command
{
    protected $signature = 'demo:race {strategy=naive : naive | pessimistic | optimistic}
                            {--workers=10} {--bets=10} {--stake=100} {--balance=5000}';

    protected $description = 'Run concurrent bets against one wallet to show (or prevent) race conditions';

    // ✅ method injection: the container builds WalletService and passes it in (no app() inside the class)
    public function handle(WalletService $wallets): int
    {
        $strategy = $this->argument('strategy');
        $workers = (int) $this->option('workers');
        $betsPerWorker = (int) $this->option('bets');
        $stake = (int) $this->option('stake');
        $startBalance = (int) $this->option('balance');

        $wallet = Wallet::factory()->create(['user_id' => User::factory()->create()->id]);
        $wallets->deposit($wallet, $startBalance, 'deposit-'.$wallet->id);

        $this->info("Wallet #{$wallet->id}: {$startBalance} cents. {$workers} processes × {$betsPerWorker} bets of {$stake} cents, strategy: {$strategy}");

        DB::disconnect();                       // a DB connection must never be shared across fork()

        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {                   // ---- child process ----
                DB::reconnect();
                exit($this->runWorker($strategy, $wallets, $wallet, $w, $betsPerWorker, $stake));
            }
            $pids[] = $pid;                     // ---- parent process ----
        }

        $accepted = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $accepted += pcntl_wexitstatus($status);   // each child returns how many bets it placed
        }

        DB::reconnect();
        $balance = Wallet::query()->whereKey($wallet->id)->value('balance_cents');
        $ledgerSum = (int) DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->sum('amount_cents');
        $expected = $startBalance - $accepted * $stake;

        $this->newLine();
        $this->table(['', 'value'], [
            ['bets accepted', $accepted],
            ['expected balance (start − accepted × stake)', $expected],
            ['actual balance in wallets table', $balance],
            ['sum of the ledger', $ledgerSum],
        ]);

        $consistent = $balance === $expected && $balance === $ledgerSum && $balance >= 0;
        $consistent
            ? $this->info('✅ consistent: the balance matches the ledger and every accepted bet')
            : $this->error('❌ inconsistent: '.abs($expected - $balance).' cents lost or created out of thin air');

        return self::SUCCESS;
    }

    private function runWorker(string $strategy, WalletService $wallets, Wallet $wallet, int $worker, int $bets, int $stake): int
    {
        $service = match ($strategy) {
            'naive' => new NaiveWalletService,
            'pessimistic' => $wallets,                 // the injected, container-built service
            'optimistic' => new OptimisticWalletService,
        };

        $placed = 0;
        for ($i = 0; $i < $bets; $i++) {
            try {
                $service->bet($wallet, $stake, "race-{$wallet->id}-{$worker}-{$i}");
                $placed++;
            } catch (InsufficientFunds) {
                // correct behaviour once the money runs out
            }
        }

        return $placed;
    }
}
