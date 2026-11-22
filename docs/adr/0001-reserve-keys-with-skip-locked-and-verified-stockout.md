# ADR 0001: Reserve keys at checkout with SKIP LOCKED and a verified-stockout fallback

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

Many buyers can try to buy the last keys of a title at the same moment. Two failure modes matter:
selling one key twice, and telling a buyer "sold out" while a key could still become theirs. A
plain `SELECT ... FOR UPDATE` on the first available key makes every buyer queue behind the same
row. `FOR UPDATE SKIP LOCKED` removes that queue, but on its own it returns nothing when the only
remaining keys are locked by transactions that will roll back (a failing checkout, a deadlock
victim), which is a false stockout. The multi-process tests measured exactly that on the original
allocator (see [architecture.md](../architecture.md#reservation-protocol-skip-locked-and-false-stockouts)).

## Decision

The key is reserved synchronously inside the checkout transaction; only delivery is queued.
`App\Services\Inventory\InventoryAllocator` is the only code path that changes a key's status or
order linkage, and it allocates in three steps:

1. Fast path: `FOR UPDATE SKIP LOCKED` takes any unlocked available key.
2. If none is unlocked, a plain read of committed data decides whether any key is available at all.
   If not, the title is sold out.
3. Otherwise wait on the locks with a plain `FOR UPDATE` (bounded by `innodb_lock_wait_timeout`):
   if the holders commit, it is a true stockout; if one rolls back, its key is taken.

The query is pinned to the `(game_id, status, id)` index with `FORCE INDEX`, so it walks keys in id
order and locks at most the row it returns. Locks are always taken in the same order (games, then
orders, then game_keys).

## Consequences

- Concurrent buyers take different keys without serialising on one row.
- "Sold out" is answered only when it is true, at the price of a second, rarer query when stock
  looks empty.
- The waiting step cannot deadlock: key holders never wait for waiters, and waiters hold no key locks.
- Checkout holds row locks briefly, so it must stay short (no external calls inside it).

## Verification

- `tests/Integration/Concurrency/CheckoutConcurrencyTest.php`: independent PHP processes with their
  own MySQL connections race for the same keys, released through a barrier.
- `tests/Integration/Database/QueryPlanTest.php`: EXPLAINs the real allocation SQL under frozen
  empty-table statistics to guard the index choice.
- `tests/Support/Invariants.php` replays the inventory ledger after every concurrency test.
