# ADR 0005: Transactional outbox for notifications

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

When an order is delivered the customer gets an e-mail. Sending it inside the delivery
transaction would put an external call inside a transaction; sending it after the commit can lose
it if the process dies in between; queueing it before the commit can announce a delivery that then
rolls back. The e-mail must also never contain key material.

## Decision

- `App\Services\Outbox\Outbox::record()` writes the message in the business transaction (it
  refuses to run outside one), inside a savepoint so a duplicate event id is a no-op without undoing
  the caller's work. Payloads carry identifiers only, which is enforced; consumers load current data
  themselves.
- `App\Services\Outbox\OutboxPublisher` claims due messages with `FOR UPDATE SKIP LOCKED`, pinned to
  the `(status, available_at)` index, and sets a lease and a `lock_token`. Completion and failure
  are conditional on that token, so a publisher whose lease expired cannot overwrite a newer outcome.
- Failures back off exponentially (30 s, 60 s, 120 s, ... capped at 1 h); after 8 attempts the
  message becomes a dead letter, which an operator can requeue (audited) from the admin console or
  with `outbox:publish --requeue-dead-letters`.
- A publisher job is dispatched after commit, and `outbox:publish` runs every minute as a safety net.

## Consequences

- A notification is never lost and never sent for a delivery that rolled back.
- Delivery to the mailer is **at-least-once**: if a publisher dies after sending but before marking
  the message published, the e-mail can be sent twice. Exactly-once would need the receiver to
  deduplicate on `event_id`.
- One extra table and a publisher process to operate and observe (`outbox:status`, admin outbox page).

## Verification

- `tests/Feature/Outbox/OutboxTest.php`.
- `tests/Integration/Concurrency/OutboxConcurrencyTest.php`: competing publishers and a SIGKILLed
  publisher.
- `tests/Integration/Database/QueryPlanTest.php`: the claim query's plan.
- `tests/Integration/Database/TransactionBoundariesTest.php`: outbox writes are refused outside a
  database transaction.
