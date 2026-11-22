<?php

/*
 * Timing and configuration invariants the reliability design depends on. If someone tunes one
 * value in isolation, these fail before production does.
 */

use App\Jobs\DeliverGameKey;
use App\Jobs\PublishOutboxMessages;
use App\Services\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;

it('keeps worker timeout < fulfillment lease < queue retry_after', function () {
    $timeout = (new DeliverGameKey(1))->timeout;
    $lease = (int) config('gamevault.fulfillment.lease_seconds');

    expect($timeout)->toBeLessThan($lease)
        ->and($lease)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($lease)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

it('keeps the outbox lease longer than the publisher job may run', function () {
    expect((new PublishOutboxMessages)->timeout)->toBeLessThan((int) config('gamevault.outbox.lease_seconds'));
});

it('has Horizon supervisors for every application queue, each timing out before retry_after', function () {
    $supervisors = collect(config('horizon.defaults'));
    $queues = $supervisors->flatMap(fn (array $s) => $s['queue'])->all();

    expect($queues)->toContain(config('gamevault.fulfillment.queue'), config('gamevault.outbox.queue'), 'default');
    $supervisors->each(fn (array $s) => expect($s['timeout'])->toBeLessThan((int) config('queue.connections.redis.retry_after')));
});

it('uses a bounded exponential retry schedule for fulfillment jobs', function () {
    $job = new DeliverGameKey(1);
    $backoff = $job->backoff();

    expect($job->tries)->toBe(count($backoff) + 1);
    foreach (array_slice($backoff, 1) as $i => $delay) {
        expect($delay)->toBe($backoff[$i] * 3);
    }
});

it('keeps the recovery threshold longer than any gap between fulfillment retries', function () {
    // After a transient error the order waits, lease relinquished, for its queue retry: at most the
    // longest backoff plus the time a busy worker needs to pick the job up. Recovering it sooner
    // would start a second retry chain next to the scheduled one.
    $job = new DeliverGameKey(1);
    $longestRetryGap = max($job->backoff()) + $job->timeout;

    expect((int) config('gamevault.fulfillment.stuck_after_minutes') * 60)->toBeGreaterThan($longestRetryGap);
});

it('uses a non-decreasing, capped exponential backoff for outbox messages', function () {
    $delays = array_map(fn (int $n) => OutboxPublisher::backoffSeconds($n), range(1, 15));

    expect($delays[0])->toBe((int) config('gamevault.outbox.backoff_base_seconds'))
        ->and(max($delays))->toBe((int) config('gamevault.outbox.backoff_cap_seconds'))
        ->and($delays)->toBe(collect($delays)->sort()->values()->all());
});

it('runs MySQL sessions at READ COMMITTED with a short lock-wait timeout', function () {
    expect(DB::scalar('SELECT @@transaction_isolation'))->toBe('READ-COMMITTED')
        ->and((int) DB::scalar('SELECT @@innodb_lock_wait_timeout'))->toBe(5);
});

it('schedules the outbox and recovery safety nets', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('outbox:publish')
        ->expectsOutputToContain('gamevault:recover-stuck')
        ->assertSuccessful();
});
