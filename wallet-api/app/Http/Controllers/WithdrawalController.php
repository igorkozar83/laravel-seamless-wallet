<?php

namespace App\Http\Controllers;

use App\Http\Requests\WithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Services\Wallet\WithdrawalService;

class WithdrawalController extends Controller
{
    public function store(WithdrawalService $withdrawals, WithdrawalRequest $request): WithdrawalResource
    {

        $withdrawal = $withdrawals->withdraw($request->user()->wallet()->firstOrFail(), $request->validated('amount_cents'), $request->header('Idempotency-Key'));

        return new WithdrawalResource($withdrawal);

    }
}
