<?php

return [

    // Redis stream the outbox relay publishes wallet events to (consumed by the reporting service).
    'events_stream' => env('WALLET_EVENTS_STREAM', 'wallet-events'),

    // How many outbox rows the relay publishes per batch.
    'relay_batch_size' => (int) env('WALLET_RELAY_BATCH_SIZE', 100),

];
