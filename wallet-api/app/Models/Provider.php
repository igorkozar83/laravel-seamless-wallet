<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'commission'])]
class Provider extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['commission' => 'decimal:4'];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
