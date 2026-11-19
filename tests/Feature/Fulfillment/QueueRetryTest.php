<?php

/*
 * Retries driven by Laravel's real queue worker (queue:work on the database driver), not by a
 * fake: the worker itself reserves the job, catches the exception, applies the job's backoff and
 * finally calls failed().
 */

use App\Actions\Fulfillment\FulfillmentClaim;
use App\Enums\FailureCode;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Models\GameKey;
use App\Models\Order;
use App\Services\Fulfillment\KeyProvisioner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Invariants;

function workFulfillmentQueueOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'fulfillment', '--once' => true, '--sleep' => 0]);
}

function failingProvisioner(int $failures): void
{
    app()->instance(KeyProvisioner::class, new class($failures) implements KeyProvisioner
    {
        public function __construct(private int $failures) {}

        public function provision(FulfillmentClaim $claim): void
        {
            if ($this->failures-- > 0) {
                throw new RuntimeException('Simulated supplier outage');
            }
        }
    });
}

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->freezeTime();
});

it('retries a failing delivery with exponential backoff, then fails the order and returns its key to stock', function () {
    failingProvisioner(PHP_INT_MAX);
    $order = Order::factory()->withReservedKey()->create();
    DeliverGameKey::dispatch($order->id);

    foreach ([5, 15, 45, 135] as $i => $delay) {
        workFulfillmentQueueOnce();

        $job = DB::table('jobs')->where('queue', 'fulfillment')->sole();
        expect($job->attempts)->toBe($i + 1)
            ->and($job->available_at - now()->getTimestamp())->toBe($delay)
            ->and($order->fresh()->status)->toBe(OrderStatus::Processing)
            ->and($order->fresh()->hasLiveLease())->toBeFalse(); // relinquished for the retry

        $this->travel($delay)->seconds();
    }

    workFulfillmentQueueOnce(); // 5th and last try

    $order->refresh();
    expect(DB::table('jobs')->where('queue', 'fulfillment')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Failed)
        ->and($order->failure_code)->toBe(FailureCode::AttemptsExhausted)
        ->and($order->attempts)->toBe(5)
        ->and(GameKey::sole()->status)->toBe(KeyStatus::Available);
    Invariants::assertAll();
});

it('delivers on a later attempt after transient failures, with no duplicate effects', function () {
    failingProvisioner(2);
    $order = Order::factory()->withReservedKey()->create();
    DeliverGameKey::dispatch($order->id);

    workFulfillmentQueueOnce();
    $this->travel(5)->seconds();
    workFulfillmentQueueOnce();
    $this->travel(15)->seconds();
    workFulfillmentQueueOnce();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->attempts)->toBe(3)
        ->and(DB::table('jobs')->where('queue', 'fulfillment')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('outbox_messages')->count())->toBe(1);
    Invariants::assertAll();
});
