<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['wallet_id', 'week_start', 'loss_cents', 'cashback_cents'])]
class CashbackPayout extends Model
{
    protected function casts(): array
    {
        return ['week_start' => 'date', 'loss_cents' => 'integer', 'cashback_cents' => 'integer'];
    }
}
