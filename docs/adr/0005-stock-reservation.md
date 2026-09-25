# ADR-0005: Stock is reserved per order line at one location, under row locks, in the status change's transaction

- Status: accepted
- Date: 2026-09-26

## Context

Overselling is the risk the roadmap names first. An order's stock has to be held from the moment it is confirmed, released if it is cancelled, and taken off on hand when it ships, and each of these has to be atomic with the status change (CLAUDE.md). Two operators, or an operator and an integration, can confirm orders for the same product at the same moment on different PHP-FPM workers.

## Decision

- **Order lines link to products by SKU.** Creating an order with an SKU that is not in the catalogue is a 422 (`unknown_sku`). The line still copies the SKU, name and price, so the order keeps what was sold. The name defaults to the product's.
- **One location per order in Phase 1.** The order names it by code (`location`), or it is the installation's default: the location named by `KANSO_DEFAULT_LOCATION`, or the only location if there is exactly one. With several locations and no default, the order must name one (422 `no_default_location`). Distributed routing across locations is Phase 3.
- **The reservation is `order_line.reserved_quantity`**, at the order's location, rather than a separate Reservation table. With one location per order, a line is either wholly reserved or not at all, so the quantity on the line says everything a Reservation row would.
- **Stock follows the status, in `OrderService::transition()`'s transaction** (`Application\Order\OrderStock`):
  - `confirm` reserves every line, all or nothing. If any product is short, nothing is reserved, and the answer is a **409 naming every short line** (`insufficient_stock`, path `lines[i].quantity`).
  - `cancel` of an order that holds stock releases it.
  - Shipping takes the reserved stock off on hand (on hand and reserved both drop), shipment by shipment (superseded in detail by ADR-0009).
  - `hold` and `release` move no stock. An order on hold from a confirmed status keeps what it holds.
  - Each change writes an inventory movement (`reservation`, `release`, `shipment`) carrying the order's id and number.
- **Row locks, not only optimistic locking.** Inside the transaction the levels involved are read with `SELECT … FOR UPDATE`, in product-id order. A second confirmation for the same product waits for the first to commit, then sees what it reserved.
  - The level's `version` (optimistic lock) and the database's `CHECK (on_hand >= reserved)` stay as further guards.
  - The order's own `version` is checked in the same transaction, so a reservation made for an order someone else changed meanwhile is rolled back with it.

## Consequences

- Concurrent confirmations get the right answer rather than a spurious conflict. `ConcurrentConfirmationTest` confirms eight orders in eight processes at once. With the lock removed, seven of them failed with a stale-version conflict even when there was stock for all eight.
- A confirmation holds row locks on its products' levels for the length of its transaction (milliseconds). Deadlocks and lock-wait timeouts are mapped to a 409, like a lost optimistic lock.
- The default location is an environment variable, so changing it is a redeploy. That is acceptable while installations have one or two locations. If operators need to change it themselves, it becomes a settings row or a flag on Location.
- Orders placed before this change may have lines with no product (backfilled by SKU where the catalogue had it). Confirming one of those is a 422 (`no_product`). Such orders that were already confirmed hold nothing: cancelling one moves no stock, and a shipment of one is refused (`not_reserved`, ADR-0009).
- When routing across locations arrives (Phase 3), a line can be reserved at several locations. `reserved_quantity` then becomes a Reservation table (line, location, quantity), as the domain model draft has it.
