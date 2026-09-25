# ADR-0011: Orders are edited before picking starts; partial cancel counts cancelled units on the line

- Status: accepted
- Date: 2026-09-28

## Context

Phase 1 needs orders to be edited before fulfillment and cancelled fully or partly (ROADMAP). A customer phones to add a second pair of socks. An address was typed wrong. One item is out of stock and will not come back, so the rest of the order ships without it. Each of these changes what the order holds in stock. Stock has to follow in the same transaction as the change (ADR-0005). Each change also needs an order event with before and after, and two operators must not overwrite each other (ADR-0004).

Shipments already count what has left on each line (`shipped_quantity`, ADR-0009). Shipments can be voided, and a voided shipment makes its units "left to ship" again (ADR-0009, amended).

## Decision

### Editing

- **`POST /api/orders/{id}/edits`**, a sub-resource like the order's other writes. It is not a `PATCH` of the order: the lines are a list of changes, and a JSON merge patch replaces an array whole. The body is `{version, lines?, customer?, shippingAddress?, billingAddress?}`. A field left out stays as it is.
  - `lines` lists changes only. Lines it does not name stay as they are.
    - `{lineId, quantity}` sets a line's ordered quantity. `0` removes the line.
    - `{sku, quantity, unitPrice, name?}` adds a line, checked exactly as on create. An unknown SKU is a 422 (`unknown_sku`).
    - A line keeps its SKU, name and price. Sending `sku`, `name` or `unitPrice` with a `lineId` is a 422 (`not_editable`). To reprice a line, remove it and add a new one.
  - `customer` takes `name` and `email`; `email: null` clears the email. The addresses are replaced whole. `billingAddress: null` clears the billing address, so billing is the shipping address.
- **When:** while the order is `pending`, `confirmed` or `allocated`, or `on_hold` from one of those, and nothing has shipped (voided shipments do not count). Otherwise the answer is a 409 (`not_editable`). Once picking starts, the warehouse works from a printed pick list, and changing the order under it would put the two out of step. To stop an order that is being picked, the operator can put it on hold or cancel items. The order detail has `canEdit`.
- **Quantities:** a line's quantity cannot go below what has shipped plus what was cancelled, and never below 1 (422 `below_shipped_or_cancelled`). An edit that would leave nothing to ship is a 422 (`nothing_left`). Leaving nothing to ship means cancelling the order, and that is a cancel, not an edit.
- **Stock:** when the order holds stock, each line the edit touched is reserved again: every unit left to ship on a kept line, nothing on a removed one (`OrderStock::follow()`, in the edit's transaction).
  - The levels are locked in product-id order, as for confirm.
  - Increases are all or nothing. A short product is a 409 (`insufficient_stock`) naming each line of the request that needed more, at `lines[i].quantity`.
  - The check is net per product. Removing a line of 8 and adding one of 9 for the same product needs 1 more.
  - Each move writes an inventory movement (`reservation` or `release`) with the order number.
- **Money:** each line total is recomputed in minor units, and the order total is their sum. Overflowing the total is a 422 at `lines`.
- **Event:** one `edited` event per edit. `before` and `after` hold only what changed. Line changes are under `lines` as `{position, sku, name, quantity, cancelledQuantity, unitPrice}`: a line in both is a changed quantity, one only in `after` was added, one only in `before` was removed. `total`, `customerName`, `customerEmail`, `shippingAddress` and `billingAddress` appear only when they changed. An edit that changes nothing writes nothing and leaves the version alone.
- A new line takes the next position after the highest the order has had. A removed line leaves a gap, so positions in older events still mean what they meant.

### Partial cancel

- **`POST /api/orders/{id}/cancellations`** with `{version, lines: [{lineId, quantity}], reason?}`.
- **`order_line.cancelled_quantity`** counts units that will not ship. `quantity` stays what was ordered, so the line explains itself.
  - `line_total = unit_price × (quantity − cancelled_quantity)`, and the order total follows. What shipped is still charged.
  - Invariants: `shipped + cancelled ≤ quantity`. While the order holds stock, `reserved = quantity − shipped − cancelled`; otherwise `reserved = 0`. The units left to ship on a line are `quantity − shipped − cancelled`. A database CHECK keeps `reserved + shipped + cancelled ≤ quantity`, and the earlier checks follow from it.
- **When:** in any status the order can be cancelled from (`pending` to `packed`, and `on_hold`), including after part of it has shipped, which is exactly the case the full `cancel` transition refuses. The order detail has `canCancelItems`. A shipped, delivered or cancelled order is a 409 (`not_cancellable`).
- **Only unshipped units:** a line can cancel at most what it has left to ship. More is a 422 at `lines[i].quantity` (`exceeds_remaining`).
- **Stock:** the cancelled units come off the line's reservation, and are released on the level in the same transaction, with a `release` movement. A pending order holds nothing, so nothing moves. Confirming it later reserves only what is not cancelled.
- **Finishing:** when every line is fully shipped or cancelled, the order finishes through the state machine (`Order::apply()`), never by setting its status:
  - **shipped** (`ship`) if any of it has shipped: what left is the order;
  - **cancelled** (`cancel`) otherwise.
  - An order on hold with part shipped cannot move to shipped (the state machine has no `on_hold → shipped`), and the hold is someone's decision. Cancelling its last units is a 409 (`release_first`): release it, then cancel them. Cancelling only some of its units is fine while on hold.
- **Event:** one `lines_cancelled` event. `before.lines` holds `{position, sku, cancelledQuantity}` for each line. `after.lines` holds `{position, sku, name, cancelled, cancelledQuantity}`, where `cancelled` is how many this time. It also records `total` before and after, and `reason` when one was given (at most 255 characters). The finishing transition writes its own `transition` event after it.
- The full `cancel` transition is unchanged. It cancels the whole order before anything ships and releases what it holds.

### Both

- Both take the version the caller saw (409 `stale_version`), as all order writes do. Both are for operators; a viewer gets 403.
- Pick lists and packing slips of the whole order leave cancelled units out.
- **UI:** the order page has "Edit order" (shortcut `e`) and "Cancel items", shown while `canEdit` or `canCancelItems` is true, each in a native `<dialog>`.
  - The edit dialog sends only what changed.
  - The cancel dialog offers each line's units left to ship, and says before sending when the cancel will finish the order as shipped or cancelled.
  - The lines table gets a "Cancelled" column when any line has cancelled units.
  - The timeline describes both new events.

## Consequences

- `quantity` on a line no longer means "units to handle". Code that needs that uses `remainingQuantity()` (to ship) or `quantity − cancelledQuantity` (to pick or pack). Confirm, shipments and documents already do.
- A voided shipment makes its units "left" again and reserved again. If the order had finished as shipped through a partial cancel, voiding reopens it (ADR-0009, amended), and the cancelled units stay cancelled.
- An edit of an order confirmed before reservations existed reserves the lines it touches in full. That is right, but it is a stock change the operator may not expect on such old orders.
- Line prices are fixed once placed. When price corrections are needed often, an `unitPrice` change on a line is a small addition: same event, no stock effect.
- Refunds are not automatic. Cancelling units of a paid order lowers the total, but the payment status stays as set by hand until payment integrations arrive (Phase 2).
