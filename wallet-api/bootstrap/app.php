<?php

use App\Exceptions\InsufficientFunds;
use App\Exceptions\TransactionIdConflict;
use App\Http\Middleware\VerifyProviderSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'provider.signature' => VerifyProviderSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // one place that turns a domain exception into an HTTP response, instead of try/catch in every controller
        $exceptions->render(fn (InsufficientFunds $e) => response()->json([
            'error' => 'INSUFFICIENT_FUNDS',
            'balance_cents' => $e->balanceCents,
            'requested_cents' => $e->requestedCents,
        ], 422));

        $exceptions->render(fn (TransactionIdConflict $e) => response()->json([
            'error' => 'TRANSACTION_ID_CONFLICT',
            'transaction_id' => $e->transactionId,
        ], 409));
    })->create();
