<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;

/**
 * "Seamless wallet": the game studio calls US on every bet and win, and we move the money.
 * The controller is thin: validation in the FormRequest, money logic in WalletService,
 * response shape in the Resource, errors in the central exception handler (bootstrap/app.php).
 */
class ProviderWalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}   // injected by the container

    public function bet(ProviderTransactionRequest $request): JsonResponse
    {
        $transaction = $this->wallets->bet(
            $this->walletOf($request->integer('player_id')),
            $request->integer('amount_cents'),
            $request->string('transaction_id'),
            $request->string('round_id'),
        );

        // 201 the first time, 200 for an idempotent retry of the same transaction_id
        return TransactionResource::make($transaction)->response()->setStatusCode($transaction->wasRecentlyCreated ? 201 : 200);
    }

    public function win(ProviderTransactionRequest $request): JsonResponse
    {
        $transaction = $this->wallets->win(
            $this->walletOf($request->integer('player_id')),
            $request->integer('amount_cents'),
            $request->string('transaction_id'),
            $request->string('round_id'),
        );

        return TransactionResource::make($transaction)->response()->setStatusCode($transaction->wasRecentlyCreated ? 201 : 200);
    }

    private function walletOf(int $userId): Wallet
    {
        return Wallet::query()->where('user_id', $userId)->firstOrFail();
    }
}
