# ADR-0006: Product CSV import is a bounded, synchronous request with a dry run

- Status: accepted
- Date: 2026-09-26

## Context

Phase 1 needs products imported from a CSV file (ROADMAP.md): upload, a preview that lists the rows that fail, then the import. Running the same file twice must change nothing, and a file has a row limit.

Pimsen's ADR says files never pass through PHP: uploads go to object storage through presigned URLs, and imports run as Messenger jobs that stream from there. That design is built for catalogues of tens of thousands of rows with many attributes. A Kanso product has four fields, and a merchant's first import is a spreadsheet of hundreds to a few thousand SKUs.

## Decision

- **One endpoint, `POST /api/product-imports`.** The body is the CSV file itself (`text/csv`), not multipart. `?dryRun=true` checks and counts but writes nothing. That is the preview, and the import is the same request without the flag. The file is sent twice rather than kept on the server between the two requests, so no upload state has to outlive a request (processes are stateless).
- **Bounded:** at most 5,000 rows and 1 MiB, the proxy's existing `client_max_body_size`. Larger files are refused (422 `too_many_rows`, or 413 from the proxy); the UI checks the size before sending.
- **Synchronous.** Checking 5,000 rows is one `SELECT … WHERE sku IN (…)` per 1,000 SKUs plus one transaction, well inside a request. No Messenger job and no object storage.
- **Matched by SKU.** A new SKU creates a product and a known one updates it. A row equal to its product is not written at all, so its version and `updatedAt` stay as they were: importing the same file again reports everything as unchanged. A column left out of the header leaves that field alone, and an empty cell clears it.
- **SKUs compare like the database does.** The `sku` column's collation (`utf8mb4_0900_ai_ci`) ignores case and accents. A file SKU that matches an existing product only that way (`abc` against `ABC`) is reported as `spelling` rather than guessed at. Two such spellings in one file are a `duplicate`.
- **Bad rows are skipped, not fatal.** Every row is checked with the same rules as the product API (`ProductRules`) before anything is written. Rows that fail are listed with their spreadsheet row number, field and code, and the rest are written in one transaction. Fixing the file and importing it again is safe, because rows already imported come back unchanged. A problem with the file as a whole (encoding, header, size, no rows) is a 422 and nothing is written.
- **Forgiving about spreadsheet output.** A UTF-8 byte-order mark is accepted. The delimiter is detected from the header (`,`, `;` or tab), since Swedish Excel writes `;`. Headers are matched ignoring case, spaces, `_` and `-`, and empty padding columns are ignored. A non-UTF-8 file is refused with a hint to save it as "CSV UTF-8".

## Consequences

- Imports larger than 5,000 rows must be split. If merchants need more, the Pimsen design (object storage plus a chunked Messenger job, with the same dry-run and report shape) replaces this endpoint's internals. The contract (`dryRun`, counts, `errors[]`) can stay.
- A preview and the import that follows are two reads of the catalogue. If someone changes a product in between, the import's counts can differ from the preview's, and the import's own response is the record of what happened.
- Product changes have no audit trail yet (CLAUDE.md requires one for orders and inventory only). An import is not recorded beyond the products' `updatedAt`.
- The CSV order import on the roadmap can follow the same shape.
