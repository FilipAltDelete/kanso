# ADR-0007: Documents are Twig templates rendered to PDF by dompdf in a worker, stored in S3, downloaded through signed links

- Status: accepted (amended 2026-09-29: one PDF for many orders)
- Date: 2026-09-26

## Context

Phase 1 needs pick lists and packing slips as PDFs, and later phases add labels, invoices and exports. Processes are stateless: no local disk beyond a request, and nothing is kept in a container between requests (CLAUDE.md). Pimsen already decided how it generates documents (its ADR on generated documents: Twig templates, `DocumentRendererInterface` with dompdf built in and Gotenberg optional, generation as a Messenger job streaming to object storage) and how it uses object storage (`async-aws/s3` with Flysystem, presigned URLs, a separate presigning client for the browser-facing address).

## Decision

- **Object storage as in Pimsen.** `Domain/Storage/ObjectStorageInterface`, implemented by `Infrastructure/Storage/S3ObjectStorage` over `async-aws/s3` and `league/flysystem-async-aws-s3`. It is configured only by the existing `S3_*` variables. Two clients: one on the internal endpoint (`S3_ENDPOINT`) for reading and writing, and one on `S3_PUBLIC_ENDPOINT` for presigning. SigV4 signs the host, so a URL signed for `minio:9000` could not be rewritten for the browser.
- **Templates are Twig**, in `backend/templates/documents/`, with HTML autoescaping and strict variables. Templates format nothing: `OrderDocumentData` hands them strings already formatted for the document's language (dates via `IntlDateFormatter`, country names via ext-intl). The printed words (`DocumentLabels`) exist in Swedish and English. A test checks that both languages have every label.
- **PDF rendering is dompdf, in-process** (`PdfRendererInterface`, `DompdfRenderer`), so an installation needs no extra service to print. dompdf is locked down: no remote fetches, no PHP or JavaScript, and file access confined to the template directory. DejaVu Sans (shipped with dompdf) covers å, ä and ö. Gotenberg can become a second implementation when print fidelity needs a real browser.
- **A document is a job.**
  - `POST /api/orders/{id}/documents` with `{type, locale}` stores a `document` row (queued) and dispatches `GenerateDocument` to the `async` transport. The answer is `202`.
  - A worker renders the PDF, writes it to `documents/<id>.pdf` (the same key on every retry, so a retry overwrites), and marks the document done.
  - `GET /api/documents/{id}` returns the status and, once done, a `downloadUrl` presigned for five minutes, with `Content-Disposition: inline; filename="pick-list-<number>.pdf"`.
  - When the worker gives up (retries spent, or an unrecoverable failure), a `WorkerMessageFailedEvent` listener marks the document failed. The reason is kept in the database and the logs, not in the API, because it can name internal hosts.
- **Reuse by order version.** A request for the same order version, type and language gets back the document that is done, or queued or running for less than ten minutes. A changed order (every change bumps `version`) gets a fresh document. A document queued longer than that is presumed lost (no worker) and not handed out again.
- **Anyone signed in may print.** A document only reads an order; it changes nothing.
- **Pick lists show SKU, name, quantity and a box to tick; packing slips carry no prices**, since they travel in the parcel and may go with a gift.

## Consequences

- The browser never downloads through PHP, and nothing is written to a container's disk except dompdf's temporary files during a render.
- The test suite and CI need the object store: `phpunit.xml.dist` points at the compose MinIO's `kanso-test` bucket, and the CI backend job starts MinIO as a step, as Pimsen's does.
- Twig is now a backend dependency. The templates are the core's own. When customers can edit templates, they must run in Twig's sandbox (as in Pimsen), and per-installation templates belong in a project bundle or a settings row, never in the core (ADR-0003).
- Documents print timestamps in UTC and say so, until an installation has a time zone setting.
- Old documents are not cleaned up yet. A retention job (delete PDFs and rows older than N days) belongs with Phase 4's retention rules.

## Amendment (2026-09-29): one PDF for many orders

A pilot printing a day's orders one by one opens every order page. The order list's bulk-action bar now prints pick lists or packing slips for the selected orders as one PDF.

- **A batch is a document.** `POST /api/orders/bulk-documents` with `{type, locale, orderIds}` queues one `document` row and one `GenerateDocument`, and is polled and downloaded through `GET /api/documents/{id}` like one order's. Such a row has no `order_id`; it lists its orders in `batch_orders` with the versions they had when asked for. The answer is `200` with the `document` (or null) and the orders `skipped`.
- **Orders that should not be printed are left out and listed** with a code, as bulk status changes list theirs (ADR-0015): `not_found`, `cancelled`, and for pick lists `on_hold` and `nothing_to_pick` (shipped or delivered). A held order can still get a packing slip. When every order is left out, no document is made. One order's own "Print" still prints whatever it is asked for.
- **At most 100 orders per document**, so one render stays a few hundred pages. The UI says so before asking.
- **Reuse by content.** `batch_key` hashes the type, the language and every order at its version: the same selection of unchanged orders gets back the document it has, under the same ten-minute rule for pending ones. Any change to any of the orders makes a fresh one.
- **Layout.** The templates' bodies are partials (`_pick_list.html.twig`, `_packing_slip.html.twig`) that one order's template and the batch template (`<type>_batch.html.twig`) both include, so the two cannot drift. Each order starts a new page, in order-number order. The footer names the batch instead of one order, and the lines table's repeating header carries the order number, so a long order's next page still says whose it is. The PDF is named by when it was asked for, e.g. `pick-lists-20260929-0812.pdf`.
- The worker renders each order as it is at render time, as for one order; an order gone since is left out, and when none is left the document fails.
