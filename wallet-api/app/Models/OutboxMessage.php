<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['topic', 'payload', 'published_at'])]
class OutboxMessage extends Model
{
    const UPDATED_AT = null;

    /** Call inside the transaction that moved the money: the event exists if and only if it committed. */
    public static function recordFor(WalletTransaction $transaction): self
    {
        return self::create([
            'topic' => 'wallet.'.$transaction->type->value,
            'payload' => [
                'transaction_id' => $transaction->id,
                'wallet_id' => $transaction->wallet_id,
                'type' => $transaction->type->value,
                'amount_cents' => $transaction->amount_cents,
                'balance_after_cents' => $transaction->balance_after_cents,
                'round_id' => $transaction->round_id,
                'occurred_at' => ($transaction->created_at ?? now())->toIso8601String(),
            ],
        ]);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
