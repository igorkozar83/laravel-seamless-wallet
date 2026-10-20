<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider_id', 'title', 'rtp', 'is_active'])]
class Game extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['rtp' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** Local scope: Game::query()->active() */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
