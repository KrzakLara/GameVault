<?php

/*
 * Deterministic stand-in for the CheapShark API, used ONLY by the E2E stack
 * (docker-compose.yml service "cheapshark-stub"). It lets Playwright exercise the market
 * reference card without depending on, or sending traffic to, the real API. Prices here are
 * fixtures, not market data.
 *
 *   GET /api/1.0/games?id=e2e-deals   -> two PC store offers
 *   GET /api/1.0/games?id=e2e-outage  -> 503 (the app retries once, then shows "unavailable")
 *   anything else                     -> 404
 */

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$id = $_GET['id'] ?? '';

if ($path !== '/api/1.0/games') {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);

    return;
}

if ($id === 'e2e-deals') {
    echo json_encode([
        'info' => ['title' => 'E2E fixture'],
        'deals' => [
            ['storeID' => '1', 'dealID' => 'fixture-1', 'price' => '14.99', 'retailPrice' => '29.99', 'savings' => '50.016672'],
            ['storeID' => '7', 'dealID' => 'fixture-2', 'price' => '17.49', 'retailPrice' => '29.99', 'savings' => '41.680560'],
        ],
    ]);

    return;
}

if ($id === 'e2e-outage') {
    http_response_code(503);
    echo json_encode(['error' => 'Service temporarily unavailable']);

    return;
}

http_response_code(404);
echo json_encode(['error' => 'unknown game']);
