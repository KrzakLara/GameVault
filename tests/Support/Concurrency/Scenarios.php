<?php

namespace Tests\Support\Concurrency;

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Fulfillment\FulfillOrder;
use App\Exceptions\CheckoutException;
use App\Models\Game;
use App\Models\User;
use App\Services\Outbox\OutboxPublisher;
use App\Support\Transactions;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Critical sections run by worker processes. prepare() does all loading up front and returns a
 * closure that performs ONLY the contended work, which runs after the barrier.
 */
final class Scenarios
{
    /**
     * @param  array<string, mixed>  $args
     * @return Closure(): array<string, mixed>
     */
    public static function prepare(string $scenario, array $args): Closure
    {
        return match ($scenario) {
            'http-checkout' => self::httpCheckout($args),
            'place-order' => self::placeOrder($args),
            'fulfill' => self::fulfill($args),
            'publish-outbox' => self::publishOutbox($args),
            'lock-pair' => self::lockPair($args),
            default => throw new InvalidArgumentException("Unknown scenario [{$scenario}]"),
        };
    }

    /**
     * Locks two rows in the given order with a pause in between. Two workers with opposite
     * orders form a genuine InnoDB deadlock; Transactions::retrying must absorb it.
     *
     * @param  array<string, mixed>  $args
     */
    private static function lockPair(array $args): Closure
    {
        return function () use ($args): array {
            $attempts = 0;
            Transactions::retrying(function () use (&$attempts, $args) {
                $attempts++;
                DB::select('SELECT id FROM games WHERE id = ? FOR UPDATE', [$args['first']]);
                usleep(300_000);
                DB::select('SELECT id FROM games WHERE id = ? FOR UPDATE', [$args['second']]);
            }, attempts: 3);

            return ['attempts' => $attempts];
        };
    }

    /**
     * A real checkout request through the full HTTP kernel: global middleware, session, auth,
     * rate limiter, route model binding, Form Request validation, controller, PlaceOrder.
     *
     * @param  array<string, mixed>  $args
     */
    private static function httpCheckout(array $args): Closure
    {
        $kernel = app(HttpKernel::class);
        $user = User::findOrFail($args['user_id']);

        // Warm the kernel (routes, middleware, config) so only the checkout runs after the barrier.
        $kernel->handle(Request::create('/up', 'GET'));

        return function () use ($kernel, $user, $args): array {
            app('auth')->guard('web')->setUser($user); // as actingAs() would

            $request = Request::create("/games/{$args['game_slug']}/checkout", 'POST', [
                'idempotency_key' => (string) $args['idempotency_key'],
            ], server: ['HTTP_ACCEPT' => 'application/json']);

            $response = $kernel->handle($request);
            $body = json_decode((string) $response->getContent(), true);

            return [
                'status' => $response->getStatusCode(),
                'order' => is_array($body) ? ($body['id'] ?? null) : null,
                'error' => is_array($body) ? ($body['error'] ?? null) : null,
                'replayed' => $response->headers->get('Idempotent-Replayed'),
            ];
        };
    }

    /** @param  array<string, mixed>  $args */
    private static function placeOrder(array $args): Closure
    {
        $user = User::findOrFail($args['user_id']);
        $game = Game::findOrFail($args['game_id']);
        $action = app(PlaceOrder::class);

        return function () use ($action, $user, $game, $args): array {
            try {
                $result = $action->handle($user, $game, (string) $args['idempotency_key']);

                return ['outcome' => $result->replayed ? 'replayed' : 'created', 'order_id' => $result->order->id];
            } catch (CheckoutException $e) {
                return ['outcome' => 'rejected', 'reason' => $e->reason, 'status' => $e->status];
            }
        };
    }

    /** @param  array<string, mixed>  $args */
    private static function fulfill(array $args): Closure
    {
        $action = app(FulfillOrder::class);
        $orderId = (int) $args['order_id'];

        return fn (): array => ['outcome' => $action->handle($orderId)->value, 'order_id' => $orderId];
    }

    /** @param  array<string, mixed>  $args */
    private static function publishOutbox(array $args): Closure
    {
        $publisher = app(OutboxPublisher::class);
        $limit = (int) ($args['limit'] ?? 10);
        $batches = (int) ($args['batches'] ?? 1);

        return function () use ($publisher, $limit, $batches): array {
            $total = ['published' => 0, 'retried' => 0, 'dead_lettered' => 0, 'stale' => 0];
            for ($i = 0; $i < $batches; $i++) {
                foreach ($publisher->publishBatch($limit) as $k => $n) {
                    $total[$k] += $n;
                }
            }

            return $total;
        };
    }
}
