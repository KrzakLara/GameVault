# ADR 0006: Domain invariants enforced by MySQL

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

The application code takes care to keep keys, orders and outbox messages consistent, but bugs,
future code paths, manual fixes and raw SQL can bypass it. For a system whose main promise is "a key
is never sold twice and never lost", the last line of defence should not depend on every caller
being correct.

## Decision

Impossible states are made unrepresentable in the schema (migrations `2026_11_19_000002` and
`2026_11_19_000003`), so MySQL rejects them independently of application code:

- `UNIQUE(game_keys.order_id)`: an order can never own two keys.
- Composite foreign key `(order_id, game_id) -> orders(id, game_id)`: a key only ever belongs to an
  order for the same game.
- `UNIQUE(code_hash)`: the same key code cannot be imported twice, even by concurrent imports.
- CHECK constraints for state agreement, for example: a key's status matches its order linkage, a
  processing order has a lease and an expiry, a failed order has a reason and a timestamp, a
  published outbox message has a publish time, currencies are three upper-case letters.

## Consequences

- A bug that would corrupt inventory fails loudly with a constraint violation instead of silently
  writing bad data.
- Schema changes must keep the constraints in step with the state machines; migrations are tested
  for rollback and re-application.
- CHECK constraints require MySQL 8.0.16+ (the project targets MySQL 8.4).

## Verification

- `tests/Integration/Database/ConstraintsTest.php`: every CHECK, UNIQUE and foreign key rejects a
  raw invalid write.
- `tests/Integration/Database/MigrationTest.php`: migrations roll back and re-apply to an identical
  schema, and a database with legacy rows is upgraded without data loss.
