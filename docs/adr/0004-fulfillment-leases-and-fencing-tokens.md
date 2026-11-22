# ADR 0004: Fulfillment with leases and fencing tokens

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

Queues deliver jobs at least once: a job can run twice, run concurrently with a copy of itself,
or die half-way when a worker crashes. Delivery also involves a slow external hand-off (the
supplier), which must never run inside a database transaction. "Exactly once" cannot be bought from
the transport; the business operation has to be made safe instead.

## Decision

`App\Actions\Fulfillment\FulfillOrder` runs in three short steps, so no transaction is open during
slow work:

1. **Claim** (transaction): lock the order, take a lease with a fresh fencing token (a ULID in
   `lease_token`) and an expiry. While a lease is live, other executions do nothing (`Busy`).
   An expired lease (crashed or hung worker) is taken over with a **new** token.
2. **Provision** (no transaction): the supplier hand-off (`KeyProvisioner`).
3. **Finalize** (transaction): only if the order still carries **our** token; otherwise the result
   is `Superseded` and nothing changes. The key is marked sold and the outbox row is written in the
   same commit ([ADR 0005](0005-transactional-outbox-for-notifications.md)).

On a transient error the worker relinquishes its lease before rethrowing, so the queue retry can
take over immediately. `DeliverGameKey` retries with backoff 5/15/45/135 s. Claims per fulfillment
cycle are capped (`max_attempts`, default 10); at the cap the order fails and its key returns to
stock. `gamevault:recover-stuck` runs every five minutes and re-dispatches orders without progress
for `stuck_after_minutes` (default 10): pending orders whose job was lost, and processing orders
whose lease expired that long ago. A recently expired lease usually belongs to an order whose queue
retry is still waiting out its backoff, so recovery leaves it alone rather than start a second
retry chain.

## Consequences

- A duplicate job is a no-op; a slow worker that lost its lease cannot finalize or fail the order.
- Timing must hold two invariants: job timeout (30 s) < lease (60 s) < queue `retry_after` (90 s),
  and the recovery threshold must exceed the longest gap between retries (backoff plus timeout).
- An order whose queue message is lost is recovered after the threshold, not as soon as its lease
  expires: the cost of never racing a scheduled retry.
- Leases rely on reasonably synchronised clocks across hosts (documented limitation).

## Verification

- `tests/Integration/Concurrency/FulfillmentConcurrencyTest.php`: real `artisan queue:work`
  processes, duplicate jobs and SIGKILLed workers.
- `tests/Feature/Fulfillment/*` (fulfillment, queue retries, recovery, supplier outage).
- `tests/Feature/ConfigInvariantsTest.php`: the timing invariants and the retry schedule.
- `tests/Feature/Fulfillment/RecoveryTest.php`: recovery leaves an order waiting out its backoff
  alone and takes over once the threshold has passed.
- `tests/Integration/Database/TransactionBoundariesTest.php`: the provisioner is called with no
  transaction open, between two committed steps.
- `scripts/verify-horizon.sh`: delivery, retries and failure on a live Horizon stack.
