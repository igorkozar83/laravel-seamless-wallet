<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('commission', 5, 4);          // 0.1200 = 12%
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->decimal('rtp', 5, 2);                 // return to player, e.g. 96.50
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider_id', 'is_active']);  // the lobby filters by both
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
        Schema::dropIfExists('providers');
    }
};
