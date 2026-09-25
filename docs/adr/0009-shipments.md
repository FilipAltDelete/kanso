# ADR-0009: Orders ship through shipments; partial shipments; the last one ships the order

- Status: accepted
- Date: 2026-09-26

## Context

Until now `ship` was a transition that took all of an order's reserved stock off on hand in one go. Real orders leave in parts: one item is back-ordered, two parcels go by different carriers, the warehouse ships what is on the shelf. The operator has to record what left, with the carrier's tracking number (typed in by hand in Phase 1; carrier integrations are Phase 2), and stock has to follow exactly.

## Decision

- **A `Shipment` is a parcel.** It records the order, the location (the order's), its lines (order line and quantity; part of a line is fine), carrier and tracking number, when it shipped, and who recorded it. Carrier and tracking number are both optional free text: a local courier may have neither. A shipment is never changed afterwards.
- **`order_line.shipped_quantity` counts what has left.** While an order holds stock, every unit of a line is reserved or shipped: `reserved + shipped = quantity`. A database CHECK keeps `reserved + shipped ≤ quantity`.
- **`POST /api/orders/{id}/shipments`** with `{version, lines: [{lineId, quantity}], carrier?, trackingNumber?, shippedAt?}`. In one transaction:
  - the order's version is checked;
  - the lines' shipped quantities go up and their reservations down;
  - the stock levels, locked in product-id order as for reservations, lose the units from on hand and from reserved, so available does not move;
  - an order event (`shipment`) and an inventory movement per line (`shipment`, with the order number) are written;
  - when nothing is left to ship, the order moves to `shipped` through the state machine, recorded as a `ship` transition.
- **Where it can ship from:** any status in which the order holds stock — confirmed, allocated, picking, packed. Not every merchant records picking and packing, so `ship` is now allowed from all four. Not while on hold, and not before confirmation (409 `not_shippable`).
- **`ship` is not a transition a person asks for.** `POST /orders/{id}/transitions` with `ship` is a 409 (`use_shipments`), and `availableTransitions` never lists it. The order detail says `canShip` instead.
- **No cancel after a shipment.** Once part of an order has left, cancelling it is a partial cancel, which is its own roadmap item; until then `cancel` is refused and not offered.
- **Refusals:** more than a line has left is a 422 at `lines[i].quantity` (`exceeds_remaining`); an unknown line, the same line twice, or a line with nothing reserved (an order confirmed before reservations existed, `not_reserved`) is a 422; a stale version is a 409 (`stale_version`). Nothing is written in any of these cases.

## Consequences

- Stock leaves on hand exactly as parcels do, and the history shows each parcel with its order number and tracking number.
- Orders already shipped by the old transition were backfilled as fully shipped (`shipped_quantity = quantity`) by the migration; they have no shipment rows.
- Delivery is still a transition (`deliver`), set by hand; tracking-based delivery is Phase 2 with the carrier integrations.
- Partial cancel, returns (Phase 3) and back-orders build on `shipped_quantity`: what is not shipped is what can still be cancelled or re-routed.
