<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request.
 *
 * Accepts an upstream X-Request-Id (e.g. from a load balancer) only if it has a safe shape, so a
 * client cannot inject newlines or markup into logs; otherwise generates a ULID. The id is put
 * into Laravel's Context, which adds it to every log record and propagates it into queued jobs
 * (fulfillment logs carry the id of the checkout that caused them), and is echoed in the
 * response header, error pages and JSON error bodies.
 *
 * Registered as global middleware (first), so it also covers error responses.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const SAFE_FORMAT = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match(self::SAFE_FORMAT, $incoming) === 1 ? $incoming : (string) Str::ulid();

        $request->headers->set(self::HEADER, $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
