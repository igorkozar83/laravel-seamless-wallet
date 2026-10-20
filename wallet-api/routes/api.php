<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\ProviderWalletController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletTransactionController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// public lobby
Route::get('/games', [GameController::class, 'index']);

// called by game studios: signed requests + rate limited
Route::middleware(['provider.signature', 'throttle:provider'])
    ->prefix('provider')
    ->group(function () {
        Route::post('/bet', [ProviderWalletController::class, 'bet']);
        Route::post('/win', [ProviderWalletController::class, 'win']);
    });

// called by players: Sanctum token
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallets/{wallet}/transactions', [WalletTransactionController::class, 'index']);
    Route::post('/withdrawals', [WithdrawalController::class, 'store']);
});

Route::apiResource('/tournaments', TournamentController::class);
