<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $fillable = [
        'wallet_id',
        'idempotency_key',
        'amount_cents',
        'balance_after_cents',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
        ];
    }
}
