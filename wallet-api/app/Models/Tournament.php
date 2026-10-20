<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    protected $fillable = [
        'name',
        'prize_pool_cents',
        'starts_at',
    ];

    protected function casts(): array
    {
        return [
            'prize_pool_cents' => 'integer',
            'starts_at' => 'datetime',
        ];
    }
}
