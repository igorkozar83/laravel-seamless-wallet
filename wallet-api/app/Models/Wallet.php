<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// balance_cents and version are deliberately NOT fillable: money only moves through WalletService.
#[Fillable(['user_id', 'currency'])]
class Wallet extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['balance_cents' => 'integer', 'version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
