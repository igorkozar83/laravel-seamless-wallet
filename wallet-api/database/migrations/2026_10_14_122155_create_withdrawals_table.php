<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('balance_after_cents');
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['wallet_id', 'idempotency_key']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
