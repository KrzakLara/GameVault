<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Fulfillment\KeyProvisioner;
use App\Services\Fulfillment\SimulatedKeyProvisioner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KeyProvisioner::class, SimulatedKeyProvisioner::class);
    }

    public function boot(): void
    {
        // Single resources render unwrapped (Inertia props and JSON alike); paginated
        // collections still render as {data, links, meta}.
        JsonResource::withoutWrapping();

        // Outside production, fail loudly instead of silently: assigning a guarded attribute
        // (e.g. is_admin, status), N+1 lazy loading, or reading an attribute that was not selected.
        // In production guarded attributes are still never assigned; they are just dropped.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->defineGates();
        $this->defineRateLimiters();
    }

    /**
     * Authorization is decided here, server-side. Hiding a link in the UI is cosmetic.
     */
    private function defineGates(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->is_admin === true);

        // Horizon's dashboard exposes job payloads and queue controls: administrators only, in
        // EVERY environment. Without this, Horizon falls back to "allow when APP_ENV=local",
        // which would open it to anyone, guests included, on the local Docker stack.
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->is_admin === true);
        Horizon::auth(fn (Request $request): bool => Gate::forUser($request->user())->allows('viewHorizon'));
    }

    /**
     * Keyed per authenticated user (per IP as a fallback), so one account cannot exhaust
     * another's budget and rotating IPs does not bypass the per-account limit.
     */
    private function defineRateLimiters(): void
    {
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute((int) config('gamevault.checkout.rate_limit_per_minute'))
            ->by('checkout:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => $this->tooManyAttempts($request, $headers)));

        RateLimiter::for('reveal-key', fn (Request $request) => Limit::perMinute((int) config('gamevault.reveal.rate_limit_per_minute'))
            ->by('reveal:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => $this->tooManyAttempts($request, $headers)));
    }

    /** @param  array<string, string|int>  $headers */
    private function tooManyAttempts(Request $request, array $headers): Response
    {
        $message = 'Too many attempts. Please wait a moment and try again.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'error' => 'too_many_requests'], 429, $headers);
        }

        return back(fallback: '/')->withErrors(['checkout' => $message])->withHeaders($headers);
    }
}
