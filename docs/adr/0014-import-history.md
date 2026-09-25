# ADR-0014: Every real CSV import is recorded with its counts and failed rows

- Status: accepted
- Date: 2026-09-28

## Context

Product and order imports (ADR-0006, ADR-0008) report their outcome in the response and nowhere else. Once the page is closed, nobody can see who imported which file, when, what it created, or which rows failed. That is the first thing asked when a catalogue or an order list looks wrong the day after an import. Product changes are now in each product's history (ADR-0013), but that shows one product at a time, not the import as a whole.

## Decision

- **An `import_run` row per import that was run for real**: its type (`products` or `orders`), who (actor id and name, copied), when, the file's name as the browser sent it (`?filename=`, path stripped, optional), the import's own counts as JSON, the number of problems, and the first 1,000 problem rows as the response listed them.
- **Previews are not recorded.** A dry run changes nothing, and an operator may preview the same file many times. A file refused as a whole (a 422: bad header, encoding, size) is not recorded either, because nothing in it was imported.
- **Recorded by the API processors, after the import has run**, in a transaction of its own. The importers (and their row parsing, which other work changes) do not know about the log. The record is therefore of what was committed. An order import writes each order in its own transaction, so a crash between the last order and the record would leave that one import unrecorded, which is acceptable for a history (the orders themselves have their events).
- **The response carries `importRunId`** (null on a preview), so a client can link to the run it just made.
- **Read through `GET /api/import-runs?type=…`** (newest first, paged, without the problem rows) **and `GET /api/import-runs/{id}`** (with them), for any signed-in role. Each import page lists its recent imports, and a run's problems open in a dialog.

## Consequences

- A merchant can answer "who imported this and what happened" without asking the person who did it, and can re-check a fixed file against the problems of the run that failed.
- Only the first 1,000 problems are kept per run; `errorCount` says how many there were. A file with more problems than that has something systematically wrong, which the first ones show.
- The file itself is not stored. Keeping it would need object storage and a retention rule (Pimsen ADR on files), which can be added later behind the same record.
- Runs are never deleted yet; they are small and few compared with orders.
