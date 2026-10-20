<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Publishes outbox rows to a Redis stream, oldest first.
 *
 *   php artisan outbox:relay          runs forever (a worker process)
 *   php artisan outbox:relay --once   publishes one batch and exits (tests, cron, debugging)
 *
 * Delivery is at-least-once: if the process dies after XADD but before marking the row,
 * the row is published again on restart. Consumers deduplicate by the outbox id, which is
 * cheaper and more honest than pretending exactly-once delivery exists.
 *
 * FOR UPDATE SKIP LOCKED lets several relays run side by side: each one grabs rows the
 * others aren't holding, so scaling out never publishes the same row twice concurrently.
 */
class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--once : Publish a single batch and exit} {--sleep=1 : Seconds to wait when the outbox is empty}';

    protected $description = 'Publish committed wallet events from the outbox table to the Redis stream';

    public function handle(): int
    {
        do {
            $published = $this->publishBatch();

            if ($this->option('once')) {
                $this->info("Published {$published} message(s).");

                return self::SUCCESS;
            }

            if ($published === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while (true);
    }

    private function publishBatch(): int
    {
        $stream = config('wallet.events_stream');
        $redis = Redis::connection('events');

        return DB::transaction(function () use ($stream, $redis) {
            $messages = OutboxMessage::query()
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit(config('wallet.relay_batch_size'))
                ->lock('for update skip locked')
                ->get();

            foreach ($messages as $message) {
                $redis->xadd($stream, '*', [
                    'outbox_id' => (string) $message->id,
                    'topic' => $message->topic,
                    'payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
                ]);
            }

            if ($messages->isNotEmpty()) {
                OutboxMessage::query()->whereKey($messages->modelKeys())->update(['published_at' => now()]);
            }

            return $messages->count();
        });
    }
}
