# ADR 0002: READ COMMITTED with whole-transaction retries

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

MySQL's default isolation level, REPEATABLE READ, takes next-key (gap) locks on locking reads.
Measured with 20 concurrent processes, the original allocator deadlocked under REPEATABLE READ, and
the new allocator still produced deadlock retries there (the waiting scan's next-key locks against
the reserver's index inserts); READ COMMITTED produced none (see
[architecture.md](../architecture.md#isolation-level)). Deadlocks and lock-wait timeouts can still
happen under any isolation level and must not surface as random 500s.

## Decision

- Every MySQL session runs at **READ COMMITTED** (`DB_ISOLATION_LEVEL`, default in
  `config/database.php`) with `innodb_lock_wait_timeout = 5` s (`DB_LOCK_WAIT_TIMEOUT`).
- No decision relies on a snapshot read: every decision is taken under an explicit row lock or is
  re-validated under one.
- `App\Support\Transactions::retrying` retries the **whole** transaction on a deadlock (1213) or
  lock-wait timeout (1205), up to 3 attempts with a short randomised delay, and logs each retry.
  It only retries at the outermost level, because InnoDB rolls back the entire transaction.
- When the attempts are exhausted, the HTTP layer answers a retryable `503` with `Retry-After`.
  Resending is safe because checkout is idempotent ([ADR 0003](0003-database-enforced-checkout-idempotency.md)).

## Consequences

- No gap-lock deadlocks on the checkout hot path.
- Correctness comes from explicit row locks and constraints ([ADR 0006](0006-domain-invariants-enforced-by-mysql.md)),
  not from the isolation level, so the code must never rely on repeatable snapshots.
- Under extreme contention a buyer may receive a 503 and retry with the same idempotency key.

## Verification

- `tests/Feature/ConfigInvariantsTest.php`: MySQL sessions run at READ COMMITTED with a short
  lock-wait timeout.
- `tests/Integration/Concurrency`: includes a genuine InnoDB deadlock and lock-wait timeouts.
