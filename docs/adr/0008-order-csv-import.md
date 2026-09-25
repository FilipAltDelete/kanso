# ADR-0008: Order CSV import groups rows by an external reference that is unique per channel

- Status: accepted
- Date: 2026-09-26

## Context

Phase 1 needs orders imported from CSV (ROADMAP.md), for example an export from a webshop that has no connector yet. ADR-0006 settled how Kanso imports a CSV file: a bounded, synchronous request with a dry run as the preview. Orders add two questions. A file has one row per line, so which rows make up an order? And what stops the same file, imported twice, from creating every order twice? Orders have no natural key like a product's SKU.

## Decision

- **Same shape as ADR-0006.** `POST /api/order-imports`, the file as the body (`text/csv`), `?dryRun=true` for the preview, at most 5,000 rows and 1 MiB. Reading the file (encoding, delimiter, header, limits) is shared with the product import (`Application/Import/CsvFile`).
- **One row per order line, grouped by `orderReference`.** Rows with the same reference (and the same `channel`, default `manual`) are one order, in file order. The order's own columns (customer, address, currency, location, `placedAt`) are taken from the first row that fills them. A later row may leave them empty, but a later row that fills one differently is an error (`inconsistent`), not silently ignored.
- **`sales_order.external_reference`, unique per channel.** The reference is stored on the order, with a unique key on `(channel_id, external_reference)`. It is NULL for orders entered by hand, and MySQL allows any number of NULLs. `POST /api/orders` accepts `externalReference` too, so connectors (Phase 2) use the same field and the same guarantee.
- **Idempotent by reference.** An order whose reference its channel already has is counted as `existing` and left alone. Its rows are not compared with the stored order, because an import never edits orders. The unique key catches a concurrent import of the same reference, and that order is reported as `taken`. Like SKUs, references compare without case or accents (the column's collation).
- **Every order goes through `OrderService`.** The importer builds the same input as the REST body and calls `create()`, or, on a dry run, `check()`, which runs the same validation without writing or taking an order number. An imported order therefore gets exactly the checks of a manual one, including the refusal of unknown SKUs. Each order is its own transaction (CLAUDE.md), so one bad order does not undo the others.
- **An order with any problem is skipped whole**, never created with some of its lines. Its problems are reported at the rows they came from: a line's at its row, and the order's at the order's first row. Fixing the file and importing it again is safe, because orders already created come back as `existing`.
- **Prices in major units.** `unitPrice` is written as a person would write it (`199.00` or `199,00`), with at most as many decimals as the currency has (from ICU: SEK 2, JPY 0), and converted to integer minor units. It never goes through a float. `quantity` is a whole number.

## Consequences

- An import of many orders takes a transaction and an order number per order, plus a product lookup per line. 5,000 lines stay within a request. A larger volume is a connector's job (Phase 2), which reuses `externalReference`.
- The preview (dry run) and the import are two reads. An order someone creates or a product someone deletes in between can make the import's counts differ from the preview's; the import's response is the record.
- A reference cannot be reused within a channel, not even after the order is cancelled. That is what makes re-importing safe.
- Imports create `pending` orders only. They are confirmed, and their stock reserved, through the normal transitions.
