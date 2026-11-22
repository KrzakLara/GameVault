<?php

return [

    /*
    | Catalogue prices are stored as integer minor units in this currency.
    */
    'currency' => 'EUR',

    'low_stock_threshold' => (int) env('GAMEVAULT_LOW_STOCK_THRESHOLD', 3),

    'checkout' => [
        // Requests per minute per user. Checkout is idempotent, so a double click is a replay,
        // not a second purchase; the limit only bounds abuse.
        'rate_limit_per_minute' => (int) env('GAMEVAULT_CHECKOUT_RATE_LIMIT', 10),
        // Bounded retry of the checkout transaction on deadlock / lock-wait timeout.
        'transaction_attempts' => 5,
    ],

    'reveal' => [
        'rate_limit_per_minute' => (int) env('GAMEVAULT_REVEAL_RATE_LIMIT', 10),
    ],

    /*
    | Timing invariants (asserted by tests/Feature/ConfigInvariantsTest.php):
    |   DeliverGameKey::$timeout (30 s) < lease_seconds (60 s) < queue retry_after (90 s)
    | A live worker therefore never outlives its own lease, and a job redelivered after a worker
    | crash always finds the dead worker's lease already expired and can take over.
    */
    'fulfillment' => [
        'queue' => 'fulfillment',
        // Simulated supplier hand-off latency. Makes the "processing" state visible in the demo.
        'simulated_latency_ms' => (int) env('GAMEVAULT_FULFILLMENT_LATENCY_MS', 0),
        'lease_seconds' => (int) env('GAMEVAULT_FULFILLMENT_LEASE_SECONDS', 60),
        // Business-level cap on claims per fulfillment cycle (queue retries + recovery re-dispatches).
        // Reaching it fails the order with attempts_exhausted and returns the key to stock.
        'max_attempts' => (int) env('GAMEVAULT_FULFILLMENT_MAX_ATTEMPTS', 10),
        // Orders without progress for this long are re-dispatched by gamevault:recover-stuck:
        // pending orders (their queue message was probably lost) and processing orders whose
        // lease expired that long ago. Must exceed the longest gap between job retries, or
        // recovery would start a second retry chain next to a scheduled retry.
        'stuck_after_minutes' => (int) env('GAMEVAULT_STUCK_AFTER_MINUTES', 10),
    ],

    'outbox' => [
        'queue' => 'notifications',
        'batch_size' => 50,
        // After this many failed deliveries a message is dead-lettered (operator requeue only).
        'max_attempts' => 8,
        // Retry delay = min(cap, base * 2^(attempts-1)): 30 s, 60 s, 120 s ... capped at 1 h.
        'backoff_base_seconds' => 30,
        'backoff_cap_seconds' => 3600,
        // A claimed message whose lease expires (publisher died) becomes claimable again.
        'lease_seconds' => (int) env('GAMEVAULT_OUTBOX_LEASE_SECONDS', 120),
    ],

    'cheapshark' => [
        'enabled' => (bool) env('CHEAPSHARK_ENABLED', true),
        'base_url' => env('CHEAPSHARK_BASE_URL', 'https://www.cheapshark.com/api/1.0'),
        'timeout_seconds' => 3,
        'retries' => 1,
        'retry_sleep_ms' => 200,
        'ttl_minutes' => 30,
        // Failures are cached briefly so an outage does not cause one API call per page view.
        'failure_ttl_minutes' => 5,
    ],
];
