# Architecture decision records

Short records of the decisions that shape GameVault's backend and frontend: the context, the
decision, its consequences and where it is verified. The decisions were made and implemented on
2026-11-19 and recorded in this form on 2026-11-22; the detailed guarantees, measurements and
crash analysis live in [../architecture.md](../architecture.md).

| ADR | Decision | Status |
| --- | --- | --- |
| [0001](0001-reserve-keys-with-skip-locked-and-verified-stockout.md) | Reserve keys synchronously at checkout with `SKIP LOCKED` and a verified-stockout fallback | Accepted |
| [0002](0002-read-committed-with-whole-transaction-retries.md) | Run MySQL at READ COMMITTED and retry whole transactions on deadlocks and lock-wait timeouts | Accepted |
| [0003](0003-database-enforced-checkout-idempotency.md) | Make checkout idempotent in the database, not in application memory | Accepted |
| [0004](0004-fulfillment-leases-and-fencing-tokens.md) | Fulfil orders with leases and fencing tokens instead of trusting exactly-once delivery | Accepted |
| [0005](0005-transactional-outbox-for-notifications.md) | Publish notifications through a transactional outbox | Accepted |
| [0006](0006-domain-invariants-enforced-by-mysql.md) | Enforce domain invariants in MySQL with CHECK constraints and composite foreign keys | Accepted |
| [0007](0007-inertia-monolith-with-bounded-polling.md) | Inertia monolith with bounded polling for order status | Accepted |
