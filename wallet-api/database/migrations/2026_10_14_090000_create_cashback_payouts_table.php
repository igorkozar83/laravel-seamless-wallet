<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per wallet per week = "this wallet already got its cashback for this week".
        Schema::create('cashback_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained();
            $table->date('week_start');                          // Monday of the week the losses are from
            $table->bigInteger('loss_cents');
            $table->bigInteger('cashback_cents');
            $table->timestamps();

            $table->unique(['wallet_id', 'week_start']);         // the idempotency guard: paid at most once
        });

        // The weekly aggregate filters the ledger by date, so the date needs an index.
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['created_at', 'wallet_id']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'wallet_id']);
        });
        Schema::dropIfExists('cashback_payouts');
    }
};
