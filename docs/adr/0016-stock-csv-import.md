# ADR-0016: Starting stock is loaded from a CSV file of counted quantities, one transaction per row

- Status: accepted
- Date: 2026-09-25

## Context

A pilot merchant starts with a few thousand SKUs already on the shelves. Products can be imported (ADR-0006), but stock can only be set one adjustment at a time on the product page, so there is no practical way to load the starting quantities. The same need comes back at every stocktake.

## Decision

- **`POST /api/stock-imports`, the same shape as the other imports.** The body is the CSV file, `?dryRun=true` is the preview, and the import is the same request without it. Limits, file handling (BOM, delimiter, header matching) and the per-row error report come from `CsvFile`, as in ADR-0006 and ADR-0008. Operators and admins can import, as with manual adjustments.
- **Columns `sku`, `location` (the location's code) and `quantity`, all required.** The quantity is the counted quantity on hand, not a change. A count can be checked against a shelf, and the file can be imported again. The products and locations must exist. Nothing is created from this file. SKUs and codes match the way the database compares them (ignoring case and accents), since nothing is created and the match is unambiguous. Each SKU and location pair may appear on one row only.
- **Each row is a count, recorded like a manual one.** The level is set by `InventoryLevel::countAs()`, and an adjustment movement with the reason `count` records who, when, before and after. There is no separate "import" movement type, so the history and the stock rules stay the same as for a manual count.
- **A row whose quantity is already on hand is not written.** A manual count that matches is recorded ("counted, and it was right"). An import that matches is not, or every re-run would add a movement per row. The same file twice therefore changes nothing. A zero count where there is no level yet creates none.
- **One transaction per row.** Each row locks its level (`SELECT … FOR UPDATE`), checks it again and writes the level and its movement together. A row that has become invalid since the check fails alone (an order reserved more than the count, or a concurrent writer won). It is reported, and the other rows still go in. Reserved stock is never counted away: a quantity below `reserved` is refused (`below_reserved`), as in a manual adjustment.
- **Rows are checked in bulk first.** Current quantities are read as plain numbers (`InventoryStoreInterface::quantities()`), not entities. A preview of 5,000 rows is a handful of queries.
- **The entity manager lets go between rows** (`TransactionInterface::forget()`). Without that, each flush checks every entity the earlier rows loaded, and 5,000 rows took over two minutes. With it they take a few seconds, well inside a request, so the import stays synchronous (as ADR-0006 argues).
- **Recorded in the import history** (ADR-0014) with the type `stock` and the counts `rows`, `changed`, `unchanged` and `failed`. "Recent imports" on the stock import page lists these runs.

## Consequences

- A pilot can load its starting stock, and later stocktakes, from a spreadsheet. The rows that fail are listed, and the fixed file can be imported again safely.
- Rows are not all-or-nothing: a file can end up partly imported if some rows fail at write time. The response and the import history show exactly which rows failed, and re-importing the fixed file only writes what differs.
- Setting stock by delta (received goods) from a file is not covered; a count is the only semantics. A receiving import, if needed, would be a separate column or endpoint with the reason `received`.
- Movements from an import cannot be told apart from manual counts except by time and actor; the import run records the file. A link from movement to run can be added if merchants ask for it.
