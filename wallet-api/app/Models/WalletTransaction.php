<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wallet_id', 'type', 'amount_cents', 'balance_after_cents', 'idempotency_key', 'round_id'])]
class WalletTransaction extends Model
{
    const UPDATED_AT = null;                 // ledger rows are never updated

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,  // the column holds 'bet', the model gives you TransactionType::Bet
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
