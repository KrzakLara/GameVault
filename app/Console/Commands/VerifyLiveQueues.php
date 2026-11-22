<?php

namespace App\Console\Commands;

use App\Actions\Checkout\PlaceOrder;
use App\Enums\FailureCode;
use App\Enums\KeyStatus;
use App\Enums\OrderStatus;
use App\Jobs\DeliverGameKey;
use App\Jobs\PublishOutboxMessages;
use App\Models\Game;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\E2eGuard;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Live verification of the Redis/Horizon pipeline (scripts/verify-horizon.sh). Places real orders
 * through the real checkout action and waits for real Horizon workers; nothing is faked. Only in
 * the isolated E2E environment, because it creates orders and simulates a supplier outage.
 */
#[AsCommand(name: 'gamevault:verify-live')]
class VerifyLiveQueues extends Command
{
    protected $signature = 'gamevault:verify-live {--skip-retry : Skip the ~4 minute retry/failed-job scenario}';

    protected $description = 'Verify Horizon supervisors, queues, retries, failed jobs and the outbox on the live E2E stack';

    /** @var list<array{string, bool, string}> */
    private array $results = [];

    public function handle(PlaceOrder $placeOrder, MasterSupervisorRepository $masters, SupervisorRepository $supervisors, JobRepository $jobs): int
    {
        try {
            E2eGuard::assertIsolated();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->checkSupervisors($masters, $supervisors);

        $customer = User::query()->where('email', 'queue@e2e.test')->firstOrFail();
        $game = Game::query()->where('slug', 'overclocked')->firstOrFail();

        $this->checkDelivery($placeOrder, $jobs, $customer, $game);

        if (! $this->option('skip-retry')) {
            $this->checkRetriesAndFailedJobs($placeOrder, $jobs, $customer, $game);
        }

        $this->table(['Check', 'Result', 'Evidence'], array_map(fn (array $r) => [$r[0], $r[1] ? 'PASS' : 'FAIL', $r[2]], $this->results));
        $failed = count(array_filter($this->results, fn (array $r) => ! $r[1]));
        $failed === 0
            ? $this->components->info(count($this->results).' live checks passed.')
            : $this->components->error("{$failed} of ".count($this->results).' live checks failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkSupervisors(MasterSupervisorRepository $masters, SupervisorRepository $supervisors): void
    {
        $master = collect($masters->all())->first();
        $this->record('Horizon master supervisor running', ($master->status ?? null) === 'running', $master ? "{$master->name}: {$master->status}" : 'no master supervisor');

        $processes = [];
        foreach ($supervisors->all() as $supervisor) {
            foreach ((array) $supervisor->processes as $queue => $count) {
                if ($supervisor->status === 'running') {
                    $processes[$queue] = ($processes[$queue] ?? 0) + (int) $count;
                }
            }
        }

        foreach (config('horizon.defaults') as $name => $supervisor) {
            foreach ((array) $supervisor['queue'] as $queue) {
                $count = $processes["redis:{$queue}"] ?? 0;
                $this->record("Queue '{$queue}' has an active supervisor", $count > 0, "{$name}: {$count} process(es)");
            }
        }
    }

    private function checkDelivery(PlaceOrder $placeOrder, JobRepository $jobs, User $customer, Game $game): void
    {
        $start = microtime(true);
        $order = $placeOrder->handle($customer, $game, (string) Str::uuid())->order;
        $delivered = $this->waitFor(fn () => $order->fresh()?->status === OrderStatus::Delivered, 60);
        $seconds = round(microtime(true) - $start, 2);

        $this->record('Fulfillment queue: order delivered by a Horizon worker', $delivered, "{$order->public_id} delivered in {$seconds}s (incl. 1.2s simulated supplier)");
        $deliverJob = null;
        $this->waitFor(function () use ($jobs, $order, &$deliverJob) {
            return ($deliverJob = $this->completedJobFor($jobs, DeliverGameKey::class, $order->id)) !== null;
        }, 15);
        $this->record(
            'Horizon recorded the completed DeliverGameKey job',
            $deliverJob !== null,
            $deliverJob ? "job {$deliverJob->id} on queue '{$deliverJob->queue}'" : 'not found among completed jobs',
        );

        $message = fn () => OutboxMessage::query()->where('aggregate_type', 'Order')->where('aggregate_id', $order->id)->first();
        $published = $this->waitFor(fn () => $message()?->status->value === 'published', 60);
        $row = $message();
        $this->record('Outbox: delivery message published', $published, $row ? "event {$row->event_id}: {$row->status->value}, attempts {$row->attempts}" : 'no outbox message');
        $publishJob = null;
        $this->waitFor(function () use ($jobs, $start, &$publishJob) {
            return ($publishJob = $this->completedJobSince($jobs, PublishOutboxMessages::class, $start)) !== null;
        }, 15);
        $this->record(
            'Notifications queue: PublishOutboxMessages processed by Horizon',
            $publishJob !== null,
            $publishJob ? "job {$publishJob->id} on queue '{$publishJob->queue}'" : 'no PublishOutboxMessages job completed since the order',
        );

        $log = (string) @file_get_contents((string) config('logging.channels.single.path'));
        $this->record('Delivery e-mail sent through the mailer (log transport)', str_contains($log, "Order {$order->public_id} for {$game->name} is ready"), 'searched '.basename((string) config('logging.channels.single.path')));
    }

    private function checkRetriesAndFailedJobs(PlaceOrder $placeOrder, JobRepository $jobs, User $customer, Game $game): void
    {
        $failedBefore = (int) DB::table('failed_jobs')->count();
        $this->call('gamevault:supplier-outage', ['state' => 'on', '--minutes' => 10]);

        try {
            $start = microtime(true);
            $order = $placeOrder->handle($customer, $game, (string) Str::uuid())->order;
            $reservedKeyId = $order->key()->value('id');

            // Sample the claim counter to measure the real gaps between attempts.
            $attemptsAt = [];
            $this->waitFor(function () use ($order, $start, &$attemptsAt) {
                $fresh = $order->fresh();
                if ($fresh && ! isset($attemptsAt[$fresh->attempts]) && $fresh->attempts > 0) {
                    $attemptsAt[$fresh->attempts] = microtime(true) - $start;
                }

                return $fresh?->status === OrderStatus::Failed;
            }, 330);
        } finally {
            $this->call('gamevault:supplier-outage', ['state' => 'off']);
        }

        $order->refresh();
        $gaps = [];
        ksort($attemptsAt);
        $times = array_values($attemptsAt);
        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = round($times[$i] - $times[$i - 1], 1);
        }

        $expectedBackoff = (new DeliverGameKey(0))->backoff();
        $gapsMatch = count($gaps) === count($expectedBackoff)
            && collect($gaps)->every(fn (float $gap, int $i) => $gap >= $expectedBackoff[$i] - 1 && $gap <= $expectedBackoff[$i] + 12);

        $this->record('Retry: job attempted exactly $tries (5) times', $order->attempts === (new DeliverGameKey(0))->tries, "attempts {$order->attempts}");
        $this->record('Retry: gaps follow the backoff schedule', $gapsMatch, 'measured '.json_encode($gaps).'s vs configured '.json_encode($expectedBackoff).'s');
        $this->record(
            'Retries exhausted: order failed, key back in stock',
            $order->status === OrderStatus::Failed && $order->failure_code === FailureCode::AttemptsExhausted
                && DB::table('game_keys')->where('id', $reservedKeyId)->value('status') === KeyStatus::Available->value,
            "status {$order->status->value}, failure ".($order->failure_code->value ?? 'none').", key #{$reservedKeyId} ".DB::table('game_keys')->where('id', $reservedKeyId)->value('status'),
        );

        $failedRow = DB::table('failed_jobs')->latest('id')->first();
        $this->record(
            'Failed job recorded in failed_jobs',
            (int) DB::table('failed_jobs')->count() === $failedBefore + 1 && str_contains((string) ($failedRow->exception ?? ''), 'SupplierUnavailableException'),
            'failed_jobs: '.$failedBefore.' -> '.DB::table('failed_jobs')->count().'; '.Str::limit(strtok((string) ($failedRow->exception ?? ''), "\n") ?: '', 90),
        );

        $horizonFailed = collect($jobs->getFailed())->first(fn ($job) => $job->name === DeliverGameKey::class);
        $this->record('Failed job visible in Horizon', $horizonFailed !== null, $horizonFailed ? "Horizon failed job {$horizonFailed->id} ({$horizonFailed->queue})" : 'not in Horizon failed list');
    }

    /** A completed Horizon job of this class whose serialized command carries this order id. */
    private function completedJobFor(JobRepository $jobs, string $class, int $orderId): ?stdClass
    {
        return collect($jobs->getCompleted())->first(
            fn ($job) => $job->name === $class && str_contains((string) $job->payload, 's:7:\\"orderId\\";i:'.$orderId.';'),
        );
    }

    private function completedJobSince(JobRepository $jobs, string $class, float $since): ?stdClass
    {
        return collect($jobs->getCompleted())->first(fn ($job) => $job->name === $class && (float) ($job->completed_at ?? 0) >= $since);
    }

    /** Polls a condition (no fixed sleeps longer than the poll interval). */
    private function waitFor(Closure $condition, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            try {
                if ($condition()) {
                    return true;
                }
            } catch (Throwable) {
                // transient read error: keep polling until the deadline
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return (bool) $condition();
    }

    private function record(string $check, bool $passed, string $evidence): void
    {
        $this->results[] = [$check, $passed, $evidence];
        $this->line(sprintf('  %s %s  <fg=gray>%s</>', $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $check, $evidence));
    }
}
