# ADR-0013: Products keep an audit trail like orders and stock

- Status: accepted
- Date: 2026-09-28

## Context

CLAUDE.md requires an audit trail (who, what, when, before and after) for every order and inventory change, and Kanso has one: `order_event` and `inventory_movement`. Products had none. Since products can be edited in the UI and bulk-changed by CSV import (ADR-0006), a wrong name or barcode could appear with no way to tell who changed it, when, or what it was before.

## Decision

- **A `product_event` row per create and per change**, written in the same transaction as the change (the order-event pattern). It records the type (`created` or `updated`), who (actor id and name, copied at the time), when, and the product's recorded fields (`sku`, `name`, `barcode`, `weightGrams`) before and after. A create has no "before".
- **The source is recorded:** `api` for the REST API (the web UI included) or `import` for a CSV import. An import that changed a hundred products is then recognisable as such in each product's history.
- **Only real changes are recorded.** An edit or an import row that leaves every field as it was writes no event (and, as before, an unchanged import row writes nothing at all).
- **Who is required where there is a person.** The API and the import pass the signed-in user or API key. Code with no person behind it (console commands, test set-up) may leave the actor out and is recorded as `system` / "System".
- **Existing products get a backfilled `created` event**, dated with their `created_at` and attributed to the system, so every history starts where the product did.
- **Read through `GET /api/product-events?product=<id>`**, newest first and paged, for any signed-in role. It is shown on the product page as "Product changes", with a line per changed field.

## Consequences

- Every product write path must go through `CatalogService` or `ProductImporter`, which record the event. Writing to `product` any other way (raw SQL, a future connector that bypasses them) would leave a gap. New write paths must record events too.
- The history grows with edit volume, with no retention policy yet, as for orders and stock.
- Locations have no audit trail yet; the same pattern applies if one is needed.
