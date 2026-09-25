# ADR-0008: Notes are order events; tags and notes do not move the order's version; payment status does

- Status: accepted
- Date: 2026-09-27

## Context

Phase 1 needs notes, tags and a payment status on orders (ROADMAP). Notes are free text with who wrote them and when, in any status, shown in the order's timeline. Tags are a free list: the order list filters by them, and bulk tagging uses the table's bulk-action bar. The payment status is `unpaid`, `authorized`, `paid`, `refunded` or `partially_refunded`, set by hand for now, and every change writes an order event. Kanso never stores card data.

Every change to an order already writes an `OrderEvent` in the same transaction, and `Order.version` is the optimistic lock. Transitions must send the version the caller saw (409 when stale; ADR-0004).

## Decision

- **A note is an `OrderEvent` of type `note`**, with the text in `after.note` (1–2000 characters). The event already records who and when and is in the timeline, and an event is never edited or deleted, so neither is a note. There is no separate note table.
- **Tags are rows in `order_tag`** (order, name), not a JSON column. The list filter is then an indexed `EXISTS`, and the tags in use can be counted (`GET /api/order-tags`). A tag has 1–64 characters and no comma, since the list filter separates tags with commas. Tags are compared ignoring case but not accents: the column's collation is `utf8mb4_0900_as_ci`, and `OrderTag::same()` compares the same way. A tag keeps the spelling it was first given. An order has at most 20 tags.
- **Tag changes are "add these, remove those"** (`POST /api/orders/{id}/tags`, `POST /api/orders/bulk-tags`), not "the tags are now X". A change that alters the tags writes one `tags_changed` event per order, with the whole list before and after. A bulk change covers up to 500 orders and is all or none: if one order is unknown or would pass 20 tags, nothing changes.
- **Notes and tags do not take or move `version`.** Adding a note only adds. Adding or removing a tag by name cannot undo someone else's change. Requiring the version would make a bulk tag from the list fail on any order that someone happened to have open, and a note or tag would make every other open page of that order stale. `updated_at` is not touched either. The event is the record of the change.
- **The payment status is part of the order**: a column on `sales_order`, default `unpaid`. It is set with the version (`POST /api/orders/{id}/payment-status`, 409 when stale), since it replaces a value that someone else may just have set. Any status may follow any other while it is set by hand. Setting the status it already has changes nothing and writes no event. A change writes a `payment_status_changed` event with the before and after values.
- **The list shows the tags and the payment status and filters by both** (`?tag=a,b` matches orders with any of the tags; `?paymentStatus=paid,refunded`). The tags come in the page's query as a fetch join, not one query per row.

## Consequences

- The timeline API has three new event types. Clients that switch on `type` have to handle them (the web UI does).
- ADR-0007 reuses a document for the same order version. A note or tag leaves the version unchanged, so a reprint may reuse the earlier PDF. That is right while pick lists and packing slips print neither notes nor tags. If they ever do, the reuse key has to include them.
- Two bulk changes that add the same tag to the same order at the same moment can collide on the primary key. The loser gets a 409 and can retry. Nothing is lost or half-applied.
- When payment integrations arrive (Phase 2), they will set the same column through the same method, as a different actor. The hand-set path stays for corrections.
