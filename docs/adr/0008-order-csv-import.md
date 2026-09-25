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

## Amended 2026-09-28: payment status, tags and a note

- **Three optional order columns: `paymentStatus`, `tags` and `note`** (ADR-0010). They are order columns like the customer's: taken from the first row that fills them, and a later row that fills one differently is `inconsistent`. The comparison is of the cell as written, so `a|b` and `b|a` disagree.
- **`paymentStatus`** is one of `unpaid`, `authorized`, `paid`, `refunded`, `partially_refunded`, ignoring case, with a space or `-` read as `_`. Anything else is `unknown_payment_status`.
- **`tags` are separated by `|`**, not by a comma, which the list filter uses and a tag may not contain. Each tag is trimmed, empty pieces are ignored, and the same tag twice (ignoring case) counts once. An invalid tag is `tag`, more than 20 is `too_many_tags`.
- **`note`** becomes one note of at most 2000 characters (`too_long`).
- **Set in the transaction that creates the order, through the order's own methods.** `OrderService::create()` takes an optional callback that runs on the new order inside its transaction, and the importer uses it to call `changePaymentStatus`, `changeTags` and `addNote`. The order therefore has its `created` event (as `unpaid`), then `payment_status_changed`, `tags_changed` and `note`, all with the creation time. `unpaid` and empty cells write nothing. A problem with any of the three is found before anything is written, in the preview too, and skips the order whole like any other.
- Idempotency is unchanged: an order the channel already has is `existing` and left alone, so a changed payment status, tags or note in a re-imported file is not applied. Changing them is the order page's job.

## Amended 2026-09-25: linking imported orders to customer records

Phase 1 promises customer records with order history, but only orders entered by hand named a customer (`customer.id`). A merchant who imported their orders got customer pages with no orders.

- **Matched on `customerEmail`, ignoring case**, like the customer's own unique key (`email_canonical`). An order with an email is linked to the customer that has it. An order without one is not linked. Names and addresses are never used to match, because they are not unique.
- **A customer is created when none has the email**, rather than the order left unlinked. Leaving it unlinked would keep the history empty for exactly the merchants this is for, and the email is already the customer's identity. The new customer gets the order's `customerName` and `customerEmail`, both as the first order of the file with that email wrote them, and no phone or addresses: the order keeps its own shipping address. Its `created` event names the importing user. An email longer than a customer's 180 characters (an order allows 255) creates no customer, and the order is left unlinked.
- **An existing customer is not changed.** If the file gives another name for the same email, the order keeps the file's name, and the customer record keeps its own. Changing a customer is the customer page's job.
- **Only for an order that is created.** The order is checked first, then the customer is created in its own transaction (customer and event together), then the order is created. An order that fails its checks leaves no customer behind. An order the channel already has (`existing`) is not touched and creates no customer, so importing the same file twice creates no second customer and links nothing twice. If another import or an operator creates a customer with the same email in between, the importer links the order to that customer.
- **Counted as `newCustomers`** in the result, the preview included ("New customers"). Several orders with the same email count once. The count is also stored in the import history.

Consequences:

- Customer and order are two transactions, not one. If the order's own write fails after the customer was created (a race on the reference, reported as `taken`), a customer with no orders is left. It is a real buyer from the file, and the next import links to it.
- Orders imported before this amendment stay unlinked. Nothing links them afterwards.
