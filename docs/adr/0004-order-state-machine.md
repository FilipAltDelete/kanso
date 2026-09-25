# ADR-0004: The order state machine is Kanso's own table; transitions are one endpoint with a version

- Status: accepted
- Date: 2026-09-26

## Context

Every order moves through `pending → confirmed → allocated → picking → packed → shipped → delivered`, and can be cancelled or put on hold before it ships (CLAUDE.md). A status may change only through the state machine, each change writes an event in the same transaction, and two operators must not silently overwrite each other.

## Decision

- **Kanso's own state machine, not `symfony/workflow`**, as in Pimsen (its ADR-037). `Domain/Order/OrderStateMachine` is a table from (status, transition) to status. `Order::apply()` is the only way to change a status. It asks the table, records an `OrderEvent` (who, what, when, before/after), and cascades it, so the event is written in the same flush and therefore the same transaction.
- **Named transitions:** `confirm`, `allocate`, `start_picking`, `pack`, `ship`, `deliver`, `cancel`, `hold`, `release`.
  - `cancel` and `hold` are allowed from any status before `shipped`; `cancel` is also allowed from `on_hold`.
  - `release` returns an on-hold order to the status it was held from (`held_from`).
  - Shipped, delivered and cancelled orders cannot be cancelled or held. Undoing a shipment is a return (Phase 3).
- **One endpoint, `POST /api/orders/{id}/transitions` with `{transition, version}`**, rather than one endpoint per transition.
  - `version` is the `Order.version` the caller last saw (Doctrine's optimistic lock).
  - A different version is `409` with the violation code `stale_version`. So is a write that loses the race at flush.
  - A transition that is not allowed from the current status is `409` with `transition_not_allowed`.
  - The order detail lists `availableTransitions`, so a client never has to copy the table.
- **Lines and the customer are copied onto the order**, not linked. The order keeps what was sold and to whom when the catalogue or a customer record changes. `customer_id` is optional and not yet a foreign key.
- **Order numbers come from a one-row MySQL sequence** (`order_number_sequence`, `LAST_INSERT_ID(expr)`). The number is taken outside the order's transaction, so a failed create leaves a gap rather than holding a lock.

## Consequences

- A new status or transition is a change to one table, and `OrderStateMachineTest` checks every status against every transition, so an unplaced one fails.
- Clients (the UI, integrations) must send the version they read. A stale screen gets a conflict to reload, never a silent overwrite.
- Stock follows the status in the same transaction: reserved on `confirm`, released on `cancel`, taken off on hand on `ship`. See ADR-0005.
- Order numbers can have gaps. Channels that bring their own numbers (Shopify and others) will need an external-reference field beside `number`.
