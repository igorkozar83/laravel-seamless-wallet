<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Wallet;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class WalletTransactionController extends Controller
{
    public function index(Wallet $wallet): AnonymousResourceCollection     // route model binding
    {
        Gate::authorize('view', $wallet);                                   // WalletPolicy@view → 403 if not yours

        // cursor pagination: "give me rows after id X" — stays fast on millions of rows,
        // unlike OFFSET 500000 which still has to walk past half a million rows
        $transactions = $wallet->transactions()->orderByDesc('id')->cursorPaginate(20);

        return TransactionResource::collection($transactions);
    }
}
