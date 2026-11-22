<?php

namespace App\Console\Commands;

use App\Support\E2eGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Database-side truth after a load test (scripts/loadtest.sh): allocations per title, oversell
 * and double-allocation checks, and fulfillment delay from the orders' own timestamps.
 * E2E database only.
 */
#[AsCommand(name: 'gamevault:loadtest-report')]
class LoadTestReport extends Command
{
    protected $signature = 'gamevault:loadtest-report {--json= : Also write the report to this file}';

    protected $description = 'Check allocation correctness and fulfillment delay after a load test (E2E environment only)';

    public function handle(): int
    {
        try {
            E2eGuard::assertIsolated();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $games = [];
        foreach (['load-test-arena', 'limited-drop'] as $slug) {
            $game = DB::table('games')->where('slug', $slug)->first();
            if (! $game) {
                $this->error("Fixture {$slug} missing: run LoadTestSeeder first.");

                return self::FAILURE;
            }
            $keys = DB::table('game_keys')->where('game_id', $game->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
            $orders = DB::table('orders')->where('game_id', $game->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
            $delays = DB::table('orders')->where('game_id', $game->id)->where('status', 'delivered')
                ->selectRaw('TIMESTAMPDIFF(SECOND, created_at, delivered_at) as s')->pluck('s')->map(fn ($s) => (int) $s)->sort()->values()->all();
            $delays = array_values($delays);

            $games[$slug] = ['keys' => $keys, 'orders' => $orders, 'fulfillment_delay_seconds' => self::stats($delays)];
        }

        $checks = [
            'no key allocated to two orders' => DB::table('game_keys')->whereNotNull('order_id')->groupBy('order_id')->havingRaw('count(*) > 1')->count() === 0,
            'no order holds a key of another title' => DB::table('game_keys')->join('orders', 'orders.id', '=', 'game_keys.order_id')->whereColumn('game_keys.game_id', '!=', 'orders.game_id')->count() === 0,
            'every live order holds exactly one key, every key-holder is a live order' => DB::table('orders')
                ->whereIn('status', ['pending', 'processing', 'delivered'])
                ->whereRaw('(select count(*) from game_keys where game_keys.order_id = orders.id) <> 1')->count() === 0
                && DB::table('game_keys')->whereIn('status', ['reserved', 'sold'])->whereNotExists(fn ($q) => $q->from('orders')->whereColumn('orders.id', 'game_keys.order_id')->whereIn('orders.status', ['pending', 'processing', 'delivered']))->count() === 0,
            'limited drop: exactly 10 orders and no key left' => array_sum($games['limited-drop']['orders']) === 10 && ($games['limited-drop']['keys']['available'] ?? 0) === 0,
            'no oversell on any title' => DB::table('games')->get(['id'])->every(fn ($g) => DB::table('orders')->where('game_id', $g->id)->whereIn('status', ['pending', 'processing', 'delivered'])->count() <= DB::table('game_keys')->where('game_id', $g->id)->count()),
        ];

        $report = [
            'config' => [
                'simulated_supplier_latency_ms' => (int) config('gamevault.fulfillment.simulated_latency_ms'),
                'horizon_fulfillment_max_processes' => config('horizon.environments.'.app()->environment().'.fulfillment.maxProcesses'),
                'db_isolation' => config('database.connections.mysql.isolation_level'),
            ],
            'games' => $games,
            'checks' => $checks,
        ];

        foreach ($games as $slug => $data) {
            $this->line("<options=bold>{$slug}</>  keys ".json_encode($data['keys']).'  orders '.json_encode($data['orders']).'  fulfillment delay (s, DB timestamps) '.json_encode($data['fulfillment_delay_seconds']));
        }
        foreach ($checks as $check => $ok) {
            $this->line(sprintf('  %s %s', $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $check));
        }

        if ($path = $this->option('json')) {
            file_put_contents((string) $path, json_encode($report, JSON_PRETTY_PRINT)."\n");
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<int>  $values  sorted ascending
     * @return array{count: int, p50: int|null, p95: int|null, max: int|null}
     */
    private static function stats(array $values): array
    {
        $pick = fn (float $p) => $values === [] ? null : $values[(int) min(count($values) - 1, (int) ceil($p * count($values)) - 1)];

        return ['count' => count($values), 'p50' => $pick(0.5), 'p95' => $pick(0.95), 'max' => $values === [] ? null : end($values)];
    }
}
