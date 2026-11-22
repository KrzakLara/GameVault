# ADR 0003: Database-enforced checkout idempotency

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

A buyer can double-click, a network can drop a response, a browser can retry, and a page can be
reloaded mid-request. Each of these must still produce at most one order. An application-level
"check, then insert" is not safe under concurrency: two identical requests can both pass the check.

## Decision

- The client sends one idempotency key per purchase intent (`useCheckout`), reused on retries, and
  never sends a price.
- The **database** is the arbiter: `UNIQUE(user_id, idempotency_key)` on `orders`. Identical
  requests that race all insert the same pair; InnoDB makes the later inserts wait on the first
  one's index entry, and when it commits they fail with a duplicate-key error and replay the
  committed order. The initial lookup is only a fast path for retries of a finished request.
- A request fingerprint is stored with the order. The same key with a different payload (another
  game) is rejected with `422 idempotency_conflict`, never turned into a second purchase.
- The price is snapshotted from the locked `games` row inside the checkout transaction
  (`App\Actions\Checkout\PlaceOrder`).
- Only an accepted checkout consumes its key: a rejected one (sold out, inactive game, rolled back)
  leaves no row, so retrying it with the same key is safe.

## Consequences

- Responses are explicit: `201` for a new order, `200` with `Idempotent-Replayed: true` for a
  replay, `409`, `422`, `429`, and a retryable `503` ([ADR 0002](0002-read-committed-with-whole-transaction-retries.md)).
- Idempotency keys are scoped per user, so one user's key can never collide with another's.

## Verification

- `tests/Feature/Checkout/CheckoutTest.php` and `CheckoutTransactionTest.php`.
- `tests/Integration/Concurrency/CheckoutConcurrencyTest.php`: identical requests from independent
  processes.
- Playwright journey 20 (duplicate submission) in `e2e/errors.spec.ts`.
