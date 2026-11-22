# ADR 0007: Inertia monolith with bounded polling for order status

- Status: Accepted
- Date: 2026-11-19 (recorded 2026-11-22)

## Context

The frontend needs a storefront, checkout, order tracking and an admin console. A separate SPA with
its own API would mean two routing layers, two authorization surfaces and a hand-maintained API
contract. Order tracking needs near-real-time status while fulfillment runs, which usually suggests
WebSockets, at the cost of another long-running service.

## Decision

- **Inertia v2 with Vue 3 and strict TypeScript.** Laravel keeps routing, authorization and
  validation; pages receive typed props. The prop shapes are mirrored in
  `resources/js/types/models.ts` and pinned by `tests/Feature/FrontendContractTest.php`. Slow or
  external data, such as the CheapShark market reference, is a deferred prop, so it never delays the
  page.
- **Bounded polling instead of push.** `resources/js/composables/useOrderPolling.ts` follows one
  order through its JSON endpoint with backoff (1 s up to 8 s), a two-minute ceiling, pauses while
  the tab is hidden and stops once the order is final or the page is left.

## Consequences

- One routing and authorization layer; customer and admin pages share the server-side policies.
- Polling is simple and cheap at this scale and needs no extra infrastructure, but it does not scale
  like push. Laravel Reverb is listed as a future improvement.

## Verification

- `tests/Feature/FrontendContractTest.php`, `StorefrontTest.php`, `Admin/AdminConsoleTest.php`.
- Playwright journeys 5 to 10 (`e2e/purchase.spec.ts`) wait for fulfillment by watching the order
  page's own polling.
