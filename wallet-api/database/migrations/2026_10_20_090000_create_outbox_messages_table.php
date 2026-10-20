<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transactional outbox: an event is written in the SAME database transaction as the money
     * movement it describes, then a relay publishes it to Redis. If Redis is down, the event
     * waits here instead of being lost — and it can never exist for a rolled-back bet.
     */
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('topic');                       // e.g. wallet.bet, wallet.win
            $table->jsonb('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
        });

        // the relay only ever asks for unpublished rows, oldest first
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
