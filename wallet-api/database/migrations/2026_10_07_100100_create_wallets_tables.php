<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_cents')->default(0);   // money as INTEGER cents, never float
            $table->char('currency', 3)->default('EUR');
            $table->unsignedInteger('version')->default(0);    // used by optimistic locking
            $table->timestamps();
        });

        // The ledger: every money movement is a row. Rows are never updated or deleted.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');                              // bet | win | rollback | deposit
            $table->bigInteger('amount_cents');                  // negative = money out
            $table->bigInteger('balance_after_cents');
            $table->string('idempotency_key')->unique();         // the provider's transaction id
            $table->string('round_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
