<?php

use App\Exceptions\CheckoutException;
use App\Exceptions\InvalidOrderTransition;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Transactions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // First in the stack, so every response, including rendered errors, carries the id.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Expected business outcomes, not errors worth an alert.
        $exceptions->dontReport([CheckoutException::class]);

        // Never flash secrets back into the session as "old input" after a validation error.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'codes']);

        $fail = function (Request $request, string $code, string $message, int $status, array $headers = [], string $field = 'checkout'): Response {
            return $request->expectsJson()
                ? new JsonResponse(['message' => $message, 'error' => $code], $status, $headers)
                : back(fallback: '/')->withErrors([$field => $message]);
        };

        $exceptions->render(fn (CheckoutException $e, Request $request) => $fail($request, $e->reason, $e->getMessage(), $e->status));

        // The exception message names internal ids; it stays in the log, the client gets a
        // generic conflict.
        $exceptions->render(fn (InvalidOrderTransition $e, Request $request) => $fail(
            $request, 'invalid_state', 'This order changed in the meantime. Please refresh and try again.', 409, field: 'order',
        ));

        // Deadlock / lock-wait timeout still failing after the bounded retries. Safe to retry:
        // checkout is idempotent. Any other database error falls through to the generic 500,
        // which never includes SQL unless APP_DEBUG is on.
        $exceptions->render(fn (QueryException $e, Request $request) => Transactions::isConcurrencyError($e)
            ? $fail($request, 'temporarily_unavailable', 'The store is very busy right now. Please try again.', 503, ['Retry-After' => '1'])
            : null);

        // Correlation id in every JSON error body, so a user-reported error can be found in logs.
        $exceptions->respond(function (Response $response) {
            if ($response instanceof JsonResponse && $response->getStatusCode() >= 400 && is_array($data = $response->getData(true))) {
                $response->setData($data + ['request_id' => Context::get('request_id')]);
            }

            return $response;
        });
    })->create();
