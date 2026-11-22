<?php

namespace Database\Seeders;

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Fulfillment\FailOrder;
use App\Actions\Fulfillment\FulfillmentClaim;
use App\Actions\Fulfillment\FulfillOrder;
use App\Enums\FailureCode;
use App\Jobs\DeliverGameKey;
use App\Models\Game;
use App\Models\User;
use App\Services\Fulfillment\KeyProvisioner;
use App\Services\Fulfillment\SupplierUnavailableException;
use App\Services\Inventory\InventoryAllocator;
use App\Support\E2eGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Deterministic fixtures for the Playwright suite (e2e/). Every journey owns its users and games,
 * so journeys do not depend on each other's side effects. Keys are synthetic and derived from a
 * hash, so a run is reproducible. Order history is created through the real checkout and
 * fulfillment actions, which gives realistic timelines, ledger movements and outbox messages.
 *
 * Refuses to run anywhere but the isolated E2E database (App\Support\E2eGuard).
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** email => [name, is_admin] */
    private const USERS = [
        'buyer@e2e.test' => ['Ava Buyer', false],        // purchase, orders, reveal, copy
        'admin@e2e.test' => ['Ada Admin', true],         // admin journeys
        'customer@e2e.test' => ['Carl Customer', false], // must not reach the admin area
        'racer@e2e.test' => ['Rita Racer', false],       // loses the race for the last key
        'rival@e2e.test' => ['Rex Rival', false],        // wins the race for the last key
        'limit@e2e.test' => ['Liam Limit', false],       // checkout rate limit
        'rush@e2e.test' => ['Rosa Rush', false],         // duplicate submissions
        'queue@e2e.test' => ['Quinn Queue', false],      // live Redis/Horizon checks (gamevault:verify-live)
        'mia@e2e.test' => ['Mia Novak', false],          // order history
        'leo@e2e.test' => ['Leo Marin', false],
        'zoe@e2e.test' => ['Zoe Kovac', false],
    ];

    /** Fixture games with a specific role: name, platform, genre, price, keys, CheapShark id (served by e2e/stubs). */
    private const FIXTURES = [
        ['Aurora Drift', 'Steam', 'Racing', 2499, 8, 'e2e-deals'],   // purchase journey + live market reference
        ['Signal Lost', 'Steam', 'Action', 1899, 5, 'e2e-outage'],   // pricing API failure
        ['Last Light Protocol', 'PlayStation', 'Shooter', 3999, 0, null], // sold out
        ['Final Key Heist', 'Xbox', 'Action', 2999, 1, null],        // race for the last key
        ['Twin Strike', 'Steam', 'Action', 1599, 6, null],           // duplicate submission
        ['Overclocked', 'Xbox', 'Racing', 1299, 12, null],           // rate limit
        ['Ember Tactics', 'PlayStation', 'Strategy', 3499, 4, null], // failed order the admin retries
    ];

    public function run(InventoryAllocator $inventory, PlaceOrder $placeOrder, FulfillOrder $fulfill, FailOrder $failOrder): void
    {
        E2eGuard::assertIsolated();

        // Seeding must not leave jobs behind for the live workers, and needs no supplier latency.
        Queue::fake();
        config(['gamevault.fulfillment.simulated_latency_ms' => 0]);

        /** @var array<string, User> $users */
        $users = [];
        foreach (self::USERS as $email => [$name, $isAdmin]) {
            $users[$email] = User::query()->forceCreate([
                'name' => $name, 'email' => $email, 'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(), 'is_admin' => $isAdmin,
            ]);
        }

        /** @var array<string, Game> $games */
        $games = [];
        foreach (GameVaultSeeder::TITLES as $i => [$name, $platform, $genre, $price]) {
            $games[$name] = $this->game($inventory, $name, $platform, $genre, $price, $i % 7 === 0 ? 1 : 5, null, featured: $i < 4);
        }
        foreach (self::FIXTURES as [$name, $platform, $genre, $price, $keys, $cheapsharkId]) {
            $games[$name] = $this->game($inventory, $name, $platform, $genre, $price, $keys, $cheapsharkId);
        }

        // Purchase history (drives "Trending": Neon Drift 3, Cyber Arena 2, Rune Keepers 1).
        foreach ([['Neon Drift', ['mia', 'leo', 'zoe']], ['Cyber Arena', ['mia', 'leo']], ['Rune Keepers', ['zoe']]] as [$game, $buyers]) {
            foreach ($buyers as $buyer) {
                $order = $placeOrder->handle($users["{$buyer}@e2e.test"], $games[$game], (string) Str::uuid())->order;
                $fulfill->handle($order->id);
            }
        }

        // A fulfillment that gave up (retryable), as the queue leaves it: one real claim per queue
        // try, each rejected by the supplier, then DeliverGameKey::failed(). The admin retries it.
        $failed = $placeOrder->handle($users['leo@e2e.test'], $games['Ember Tactics'], (string) Str::uuid())->order;
        $rejectingSupplier = app()->make(FulfillOrder::class, ['provisioner' => new class implements KeyProvisioner
        {
            public function provision(FulfillmentClaim $claim): void
            {
                throw new SupplierUnavailableException('Simulated supplier outage');
            }
        }]);
        for ($try = 1; $try <= (new DeliverGameKey($failed->id))->tries; $try++) {
            try {
                $rejectingSupplier->handle($failed->id);
            } catch (SupplierUnavailableException) {
                // transient failure: the lease was relinquished, exactly as before a queue retry
            }
        }
        $failOrder->handle($failed->id, FailureCode::AttemptsExhausted, ['exception' => 'SupplierUnavailableException']);
    }

    private function game(InventoryAllocator $inventory, string $name, string $platform, string $genre, int $price, int $keys, ?string $cheapsharkId, bool $featured = false): Game
    {
        $slug = Str::slug($name);
        $game = Game::query()->forceCreate([
            'slug' => $slug, 'name' => $name, 'platform' => $platform, 'genre' => $genre, 'price_cents' => $price,
            'currency' => 'EUR', 'is_featured' => $featured, 'cheapshark_id' => $cheapsharkId,
            'description' => "Discover {$name}, an original fictional game in the GameVault demo catalogue.",
        ]);

        if ($keys > 0) {
            // Synthetic, deterministic codes (they match the admin import format).
            $codes = array_map(fn (int $n) => 'GVE2E-'.strtoupper(substr(sha1("{$slug}#{$n}"), 0, 5)).'-'.strtoupper(substr(sha1("{$n}#{$slug}"), 0, 5)), range(1, $keys));
            DB::transaction(fn () => $inventory->import($game, $codes));
        }

        return $game;
    }
}
