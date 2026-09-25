# ADR-0015: Bulk status changes move each order on its own

- Status: accepted
- Date: 2026-09-28

## Context

Operators need to confirm, hold or cancel many orders at once, for example the orders a CSV import just created (ADR-0008). Bulk tags (ADR-0010) are all-or-none: an unknown order, or one over the tag limit, changes nothing. A status change is different. Confirming reserves stock, and in a batch of fifty a few orders will usually be short of stock, on hold, or changed by someone else since the list was loaded. All-or-none would make one short order block the other forty-nine.

## Decision

- **`POST /api/orders/bulk-transitions`** with `{transition, orders: [{id, version?}]}`, at most 500 orders.
- **Each order moves on its own, through `OrderService::transition()`**, in its own transaction, exactly as from its order page: the same state machine, stock reservation and release, and events. CLAUDE.md's "each order change is one transaction" holds per order.
- **An order that cannot move is reported, not fatal.** The response lists `moved` (id, number, new status and version) and `failed` (id, number, and the code and message the order would have got alone: `insufficient_stock`, `transition_not_allowed`, `stale_version`, `use_shipments`, `not_found`…). A request that is wrong as a whole (unknown transition, no orders, too many) is a 422 and nothing moves.
- **Versions travel with the orders the client has loaded**, so an order someone changed since the list was loaded fails as `stale_version` instead of being moved from a state the operator never saw. Selected orders the client knows only by id (on another page) are moved from their current version.
- **The UI asks before a bulk cancel** (a checkbox in the dialog), shows the outcome, and keeps the dialog open to list the orders that did not move and why.
- **Doctrine's entity manager is reset after a failed transaction** (`DoctrineTransaction`). Doctrine closes it when work inside a transaction throws, which made every order after the first failure fail as "the EntityManager is closed". Resetting it makes any later work in the same request start clean, not only bulk transitions.

## Consequences

- A bulk action can end half-done, by design; the response says exactly which half. Re-running the same action on the same selection is safe: the orders that already moved fail as `transition_not_allowed` (or `stale_version`) and nothing happens to them.
- 500 orders are 500 transactions in one request. That is fine at Phase 1 volumes and stays within the request timeout; larger batches would become a Messenger job with the same per-order semantics.
- Entities loaded before a failed transaction are detached once the manager is reset. Code that continues after catching a failure must load what it needs again, as `OrderService::transition()` does.
