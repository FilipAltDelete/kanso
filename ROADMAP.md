# Kanso OMS: Roadmap

This roadmap lists what Kanso lacks compared with a complete web-based Order Management System, in the order it should be built. It is based on a review of the code on 2026-09-29 and replaces the earlier phase-based roadmap (Phase 0–5).

**Current milestone: M1 (Harden and ship what exists).**

## How to read this

- Milestones **M1–M9** are in dependency order. A later milestone assumes the earlier ones.
- Every item is a checkbox. Tick it when it lands and link the ADR that records the decision.
- Text in parentheses describes the code as it was on 2026-09-29.
- There are no dates. The order is the plan; dates depend on who is building.

## Where Kanso stands

**Built and working**

| Area | What exists |
|---|---|
| Orders | Manual entry and CSV import; state machine with hold and release; editing and partial cancel before picking; notes, tags, hand-set payment status; bulk status changes, tagging and printing; a timeline of every change |
| Stock | Levels per product and location; reservation on confirm in the status change's transaction, with row locks and database constraints; adjustments with reason codes; an append-only movement history; CSV stock counts |
| Fulfillment | Partial shipments with carrier and tracking typed in; correct or void a shipment; pick lists and packing slips as PDFs, one order or up to 100 |
| Catalog and customers | Products (SKU, name, barcode, weight), locations, customers with addresses and a change history; CSV product import |
| Platform | JWT login with a rotating refresh cookie, API keys, three roles, rate limits, RFC 7807 errors, optimistic locking, an OpenAPI document, metrics, tracing and alert rules; CI with lint, static analysis, unit, functional and end-to-end tests |
| UI | A tabbed workspace with split panes; tables with filters, sorting, paging and bulk actions; keyboard shortcuts; Swedish and English; themes |

**What this adds up to:** a well-tested manual order desk for one warehouse. Every order arrives by hand or by file, and every shipment is typed in. Nothing connects Kanso to another system, and it is not deployed anywhere.

**The largest gaps**

1. **No integration foundation.** The core dispatches no events, `kanso/contracts` is empty, and there is no scheduler, no email, no webhooks and no settings storage. Every connector depends on these.
2. **An order's money is only the sum of its lines.** There is no tax, discount, shipping charge, payment record, refund or invoice.
3. **One location per order.** There is no routing, backorder, transfer or inbound stock.
4. **No returns.**
5. **Not production-ready.** There are no security headers, no TLS setup, no backups, no releases and no deploy target.

## Principles

- **The order is the core object.** Every feature creates, changes, routes or reports on an order.
- **Every change is recorded,** with who, what, when, before and after.
- **The API comes first.** The web UI uses the same public API as integrations.
- **Fix before building.** M1 comes before any new feature.
- **Foundations before connectors.** Events, jobs, settings and credentials are built once in M2; every connector then uses them.
- **The money model before the channels.** Connectors map onto a complete order (M3) so that imported orders do not need migrating later.
- **Integrate, do not rebuild.** See [Not on the roadmap](#not-on-the-roadmap).

---

## M1: Harden and ship what exists

**Goal:** the current features are safe to run for a pilot merchant on a real host.

**Security**
- [x] Security headers and a strict Content Security Policy at the proxy (none are set) ([ADR-0018](docs/adr/0018-security-headers-and-proxy-trust.md))
- [x] TLS termination documented and `TRUSTED_PROXIES` set, so the refresh cookie is `Secure` and the login limiter sees the real client address (neither holds behind a TLS proxy) ([ADR-0018](docs/adr/0018-security-headers-and-proxy-trust.md))
- [x] Login throttling per address and in total, not only per email and address; a limit on `/api/auth/refresh`; the same response time for unknown and known accounts ([ADR-0019](docs/adr/0019-authentication-hardening.md))
- [x] Refresh token reuse detection that revokes the whole token family; atomic token consumption ([ADR-0019](docs/adr/0019-authentication-hardening.md))
- [x] Document generation requires Operator (a Viewer can create documents, jobs and stored files) ([ADR-0019](docs/adr/0019-authentication-hardening.md))
- [ ] Refuse to start in production with the default `APP_SECRET`; require a password change at the first admin login
- [ ] `/metrics` protected by more than the proxy's routing; `/health/ready` without raw exception messages
- [x] The web and proxy images run as a non-root user ([ADR-0018](docs/adr/0018-security-headers-and-proxy-trust.md))
- [x] A security log for failed logins, role changes and key creation (the core writes no log lines of its own) ([ADR-0019](docs/adr/0019-authentication-hardening.md))
- [ ] An audit trail for users, API keys and locations

**Correctness**
- [ ] Validate currencies against ISO 4217 and order address countries against ISO 3166 (both are pattern checks only, so `ABC` passes)
- [ ] Optimistic locking on customers (the last write wins)
- [ ] Link orders created through the API to customers by email, as the CSV import does; make `customer_id` a foreign key
- [ ] Load line counts for the order list in one query (one query per row today)
- [ ] Step back one status before shipping, to undo a mis-click (`start_picking` permanently blocks editing; `deliver` blocks voiding)
- [ ] A reason on hold and on full cancel
- [ ] Stock adjustments by a delta lock the row instead of comparing versions (a busy SKU answers 409 repeatedly)
- [ ] A counted-at time on stock counts, so a shipment between the count and the import is not overwritten
- [ ] A rule for duplicate barcodes (uniqueness is not enforced)
- [ ] Tests for currencies with 0 and 3 decimals, concurrent confirmation across several products, and an adjustment racing a reservation

**Frontend**
- [x] An error boundary (a render error blanks the whole app) ([ADR-0020](docs/adr/0020-frontend-error-and-session-handling.md))
- [x] An expired session returns to the login page (the UI stays signed in and shows 401 errors) ([ADR-0020](docs/adr/0020-frontend-error-and-session-handling.md))
- [x] Code splitting per route, and languages loaded on demand ([ADR-0020](docs/adr/0020-frontend-error-and-session-handling.md))
- [x] Compression in the frontend's Nginx ([ADR-0018](docs/adr/0018-security-headers-and-proxy-trust.md))
- [ ] The remaining hard-coded English strings and unformatted numbers go through `t()` and the locale helpers
- [x] Order list and order detail refresh on their own (only the dashboard polls) ([ADR-0020](docs/adr/0020-frontend-error-and-session-handling.md))
- [ ] Accessibility: a keyboard alternative to dragging tabs, 24 px targets, unique ids when a page is open in two tabs, a page title per route, axe tests in CI
- [ ] Tablet: the sidebar collapses by itself, and tab handling works by touch

**Operations**
- [ ] Choose the deploy target; publish images to a registry with version tags; deploy to staging automatically
- [ ] A real version in the images from the git tag (it is hard-coded to `0.1.0-dev`, which leaves the mixed-releases alert inert); a changelog, a release process and an upgrade guide
- [ ] Backup and restore of MySQL and object storage, with a tested restore (Pimsen's `docs/operations` is the starting point)
- [ ] Graceful worker shutdown (`pcntl` is not installed); health checks for the worker and proxy; object storage in the readiness check
- [ ] Decide what may be lost with Redis: it holds refresh tokens, rate limits, the cache and all metric counters in one instance
- [ ] Scanning in CI: Composer and npm audits, container images, secrets; automated dependency updates
- [ ] Demo and seed data for development and sales demos
- [ ] A runbook for each alert; `LICENSE` and `SECURITY.md`

**Documentation**
- [ ] Generate the product spec (`docs/OMS-SPEC.md`) from this roadmap
- [ ] Bring `CLAUDE.md` and `docs/domain-model.md` in line with the code: both describe events after commit, a Reservation entity and a Return entity that do not exist yet; add an ADR index

**Exit criteria:** a pilot merchant runs daily operations on a hosted installation; a restore from backup has been tested; every item under Security is closed.

---

## M2: Integration foundation

**Goal:** everything a connector needs exists once, in the core.

**Depends on:** M1.

- [ ] **Domain events, dispatched after commit** through a transactional outbox: order created, changed, transitioned, shipped; stock changed; customer changed
- [ ] **`kanso/contracts`:** interfaces, data objects and events for reading and writing orders, stock, shipments and customers (the package holds only a PHPStan rule)
- [ ] **Outbound webhooks:** subscriptions, signed payloads, retries, a delivery log, and replay
- [ ] **`Idempotency-Key`** on every write endpoint
- [ ] **Recurring jobs** with Symfony Scheduler (in the stack description, not installed)
- [ ] **Settings storage** with an API and a UI: default location, company details, number series, time zone. This replaces env vars such as `KANSO_DEFAULT_LOCATION`, which need a redeploy to change
- [ ] **Connector framework:** a connector interface, encrypted credentials, sync state per connection, and a transport and rate limit per connector
- [ ] **Channel management:** create and configure channels through the API and UI (only the seeded `manual` channel exists)
- [ ] **Inbound order contract** for any channel: create or update by external reference, including changes and cancellations after the first import
- [ ] **Job monitoring:** an API and a screen for queues and failed messages, with retry and discard (console commands only today)
- [ ] **Imports and bulk actions as background jobs** with progress, and the uploaded file kept in object storage (they run inside the request, limited to 5,000 rows)
- [ ] **Email:** Symfony Mailer with Swedish and English templates; password reset and user invitations
- [ ] **API maturity:** a versioning policy, a published reference rendered from the OpenAPI document, API key scopes per resource, a CORS policy, and cursor pagination for large lists
- [ ] **Extension tooling** named in `docs/extensions.md`: project images, `kanso:extensions:check`, a core test kit, and frontend extension points

**Exit criteria:** the sample project bundle receives an order event and records a shipment using only `kanso/contracts`; an external consumer receives signed webhooks with retries.

---

## M3: The complete order

**Goal:** an order from any web shop is represented exactly, to the smallest currency unit.

**Depends on:** M2 (settings, events).

**Amounts**
- [ ] Tax per line: rate, amount, and whether prices include tax
- [ ] Discounts on lines and on the order; shipping charges and fees; rounding
- [ ] Order totals computed from these parts (the total is the sum of the lines)
- [ ] A base currency and exchange rate on each order, for reporting across currencies

**Payments**
- [ ] Payment records: authorization, capture, refund and void, each with an amount and the provider's reference. Never card data
- [ ] Payment status derived from the records (it is one hand-set field, and any value may follow any other)
- [ ] Refund records linked to cancellations and, later, returns

**Documents**
- [ ] Invoices and credit notes with number series; an order confirmation
- [ ] Templates the merchant can adjust: logo, company details, texts
- [ ] Documents show the installation's time zone (UTC today); a retention period for stored documents

**Order data**
- [ ] Custom fields on orders, lines, customers and products; gift message and delivery instructions
- [ ] Shipping method on the order: requested service, pickup point, requested delivery date
- [ ] Ship-by date and priority
- [ ] Hold reasons: payment, fraud check, address, stock, and merchant-defined
- [ ] Draft orders, duplicating an order, and changing location or line price before picking
- [ ] Export of orders to CSV and Excel

**Customers**
- [ ] Merge duplicates; delete or anonymise
- [ ] A customer filter on the order list (the customer page stops at 50 orders)
- [ ] Company name and VAT number, for business customers
- [ ] Address validation by country

**Exit criteria:** an order with VAT, a discount, a shipping fee and a captured payment produces an invoice that matches the web shop's totals exactly.

---

## M4: Channels, carriers and automation

**Goal:** orders arrive, ship and report back without manual steps.

**Depends on:** M2, M3.

**Sales channels**
- [ ] Connectors for Shopify and WooCommerce first, then one marketplace
- [ ] Pull orders, changes and cancellations; push fulfillment and tracking
- [ ] Push stock to channels on change and by a scheduled full sync, with a buffer per channel
- [ ] Product sync from a PIM (Pimsen) or from the channel: SKU, name, barcode, weight, dimensions, image

**Carriers**
- [ ] A shipping aggregator (for example nShift or Shipmondo) covering PostNord, DHL, Bring, Budbee and Instabox: services, pickup points, labels, customs documents
- [ ] Parcels on a shipment: several per shipment, each with weight and dimensions
- [ ] Labels as PDF and ZPL, one or many; return labels
- [ ] Tracking events from the carrier; `delivered` set from tracking (it is a manual transition); delivery problems flagged
- [ ] A configurable carrier list (seven names are hard-coded in the Ship dialog)

**Payments and accounting**
- [ ] Payment provider connectors (for example Klarna, Stripe, Adyen): capture on shipment, refund
- [ ] Accounting export (for example Fortnox, Visma, Business Central): invoices, credit notes, payments

**Notifications**
- [ ] Customer emails: confirmation, shipped with tracking, delivered, delayed; templates per channel and language
- [ ] Internal alerts: low stock, failed sync, orders stuck in a status

**Automation**
- [ ] A rules engine of conditions and actions: tag, hold, set priority, choose shipping service, choose location, notify. Each rule can be tested against past orders, and every action it takes is recorded
- [ ] Automatic progress: confirm when paid, allocate when stock is reserved
- [ ] A sync health view per connector, with alerts

**Exit criteria:** at least 80% of the pilot merchant's orders go from arrival to shipped with no manual step besides picking and packing.

---

## M5: Fulfillment workspace

**Goal:** the warehouse floor works in Kanso with a scanner and a keyboard.

**Depends on:** M4 (labels).

- [ ] Pick queue: orders to pick, sorted by ship-by date and priority, assignable to a picker
- [ ] Batch picking: one pick list for many orders, sorted by shelf
- [ ] A shelf hint per product and location, printed on pick lists. A text field, not bin management
- [ ] Pack station: scan the order, scan each item to verify it, weigh, choose the parcel, print label and slip, ship
- [ ] Barcode input from handheld scanners and from a device camera (the barcode field is stored and used nowhere)
- [ ] Record who picked and packed, and when (the status steps capture no data)
- [ ] Short picks: report a missing item while picking, then adjust stock and backorder or cancel the line
- [ ] Direct printing to label and document printers, without a download
- [ ] An installable web app for tablets and handhelds that tolerates short connection losses
- [ ] Handoff to an external warehouse or WMS: send orders out, receive shipment confirmations

**Exit criteria:** a packer ships an order without touching the mouse; time from pick to ship is measured per order.

---

## M6: Inventory depth and multiple locations

**Goal:** merchants with several warehouses and stores never oversell and always know what they can promise.

**Depends on:** M2 (events), M4 (stock push).

**Locations and routing**
- [ ] A Reservation table (line, location, quantity) in place of `order_line.reserved_quantity`, as ADR-0005 anticipates
- [ ] Order routing: choose the location or locations for an order by stock, priority, distance and cost; re-route by hand
- [ ] One order shipped from several locations
- [ ] Location properties: type (warehouse, store, external warehouse, virtual), priority, whether it fulfils orders; archive

**Availability**
- [ ] Backorders and pre-orders: confirm with short stock, allocate when stock arrives (short stock refuses the confirmation today)
- [ ] Available-to-promise: what is available now plus what is expected, by date
- [ ] Safety stock, low-stock thresholds and reorder points per SKU and location, with alerts
- [ ] Reservations on unpaid orders expire

**Stock handling**
- [ ] Stock states: damaged, quarantined, in transit, incoming ("damaged" is a reason that removes stock)
- [ ] Transfers between locations: send, in transit, receive
- [ ] Purchase orders and inbound receiving, kept light: expected, received, differences
- [ ] Stocktake sessions: count, review the differences, approve; cycle counts
- [ ] Merchant-defined adjustment reasons; CSV import of received quantities as deltas

**Products**
- [ ] Bundles and kits: a bundle SKU reserves its components
- [ ] Product data for shipping and customs: dimensions, HS code, country of origin, dangerous goods, image, cost price, tax class
- [ ] Variant grouping, units of measure and pack sizes
- [ ] Lot or batch, serial number and expiry date, switched on per product
- [ ] Archive products (nothing can be deleted or deactivated)

**Views**
- [ ] Stock overview across products and locations, with search, sorting, and low-stock and out-of-stock filters
- [ ] A movement log for all products, filtered by type, reason, date, order and user
- [ ] Which orders hold a SKU's reserved stock
- [ ] Inventory export and stock valuation

**Exit criteria:** a merchant with two warehouses and a store has orders routed automatically, with no overselling on any channel.

---

## M7: Returns and after-sales

**Goal:** goods coming back are handled as carefully as goods going out.

**Depends on:** M3 (refunds), M4 (return labels), M6 (stock states).

- [ ] A Return entity with its own state machine: requested, approved, in transit, received, inspected, resolved
- [ ] Return reasons, item condition, and what happens to the item: restock, damaged, discard
- [ ] Restocking through stock movements, in the transaction that receives the return
- [ ] Refunds: full or partial, shipping, restocking fee, sent through the payment provider, with a credit note
- [ ] Exchanges: a replacement order linked to the return
- [ ] A returns portal for customers: find the order, choose items, get a return label
- [ ] Return policy rules: return window, items that cannot be returned
- [ ] An order tracking page for customers
- [ ] Claims for parcels lost or damaged in transit; reshipment
- [ ] Related orders shown on the order page: replacements, reshipments, returns

**Exit criteria:** a customer returns one item of a shipped order through the portal, and the refund, the credit note and the stock follow with no manual bookkeeping.

---

## M8: Operator experience and insights

**Goal:** operators find anything in seconds, and merchants see how their operation performs.

**Depends on:** M2 (settings); analytics items depend on M4–M7 data.

**Finding and working**
- [ ] Global search across orders, customers and products; a command palette
- [ ] Full-text search, with a search engine if MySQL is not enough (search scans with `LIKE '%…%'`)
- [ ] Named saved views stored on the server, private or shared (views live only in the URL)
- [ ] Table columns: show, hide, reorder, resize, pin; row density; select everything matching a filter; export
- [ ] Inline editing in tables; undo for destructive actions
- [ ] Order assignment to a user; mentions in notes
- [ ] Live updates: decide between polling and push (SSE or Mercure)

**Preferences and settings**
- [ ] User preferences stored on the server: language, time zone, theme
- [ ] Settings screens for tags, reason codes, carriers, document templates, webhooks and integrations

**Visibility**
- [ ] A notification centre and an activity feed
- [ ] An audit log for the whole installation, with filters and export
- [ ] Dashboards with date ranges and charts: volume, backlog, order age, ship-by compliance, fulfillment time, return rate, channel mix
- [ ] Inventory analytics: stock-outs, slow movers, turnover, reorder suggestions
- [ ] Reports and scheduled exports by email, object storage or SFTP

**Help**
- [ ] A setup wizard for a new installation; in-app help; a merchant help centre

**Exit criteria:** an operator reaches any order, customer or product from the keyboard in under five seconds; the merchant's weekly report needs no spreadsheet.

---

## M9: Enterprise, compliance and scale

**Goal:** Kanso passes a security review and carries a large merchant.

**Depends on:** M1; may run alongside M5–M8.

**Access**
- [ ] Two-factor authentication (TOTP and passkeys); single sign-on (OIDC and SAML)
- [ ] A session list per user and a login history
- [ ] Custom roles with granular permissions; users limited to locations; an address allow-list per API key
- [ ] JWT signing key rotation and a token denylist; a check against known breached passwords

**Compliance**
- [ ] GDPR: export and erasure of a person's data; retention rules for documents, events, movements and import files; no personal data in logs
- [ ] A penetration test; a software bill of materials per release
- [ ] An external accessibility audit against WCAG 2.2 AA

**Scale**
- [ ] One million orders per installation: load tests on seeded data, performance budgets in CI, archiving, read replicas, a cached dashboard
- [ ] Zero-downtime deploys: expand-and-contract migrations checked in CI; rollback
- [ ] Service level objectives and alert routing; alerts on MySQL, Redis, object storage, disk, certificates, and business signals such as orders stuck in a status
- [ ] A disaster recovery plan with recovery time and recovery point targets
- [ ] Automated provisioning of new installations, following Pimsen's customer onboarding

**Test coverage**
- [ ] End-to-end tests in Firefox and Safari, in Swedish, and at tablet width; visual regression tests

**Exit criteria:** an independent penetration test has no open high-severity findings; list views hold p95 under 500 ms at one million orders.

---

## Later: omnichannel and growth

Not planned in detail until M1–M7 are done.

- Click and collect, ship from store, and stock reserved in stores
- Business customers: company accounts, price lists, credit terms, approval flows, EDI
- Cross-border trade: duties, customs declarations, and EU one-stop-shop VAT
- Dropshipping: supplier purchase orders created from order lines
- Subscriptions and recurring orders
- A marketplace of integrations and an app framework for third parties
- Demand forecasting, anomaly and fraud detection, suggested routing rules

## Not on the roadmap

Kanso integrates with these systems and does not rebuild them.

| System | Kanso's part |
|---|---|
| Warehouse management (bin-level stock, putaway, replenishment, labour planning) | A shelf hint on pick lists (M5) and a handoff to an external warehouse or WMS (M5) |
| ERP and accounting (ledger, purchasing, supplier management) | Light purchase orders (M6) and an accounting export (M4) |
| Product information (rich content, translations, media) | Syncs the fields it needs from Pimsen or the channel (M4) |
| Storefront, cart and checkout | Receives the finished order |
| Payment processing and card data | Records transactions and calls the provider (M3, M4) |
| CRM and marketing automation | Sends transactional messages only (M4) |
| Multi-tenancy | One installation per customer (ADR-0002) |

## Cross-cutting work (every milestone)

| Area | Practice |
|---|---|
| Testing | Unit tests on the state machines, allocation and money; contract tests per connector; end-to-end tests of each new flow |
| Data integrity | One transaction per order or stock change, optimistic locking, idempotent imports and jobs |
| Audit | Every new entity writes events with who, what, when, before and after |
| Observability | A metric, a dashboard panel and an alert with a runbook for every new job or connector |
| Contracts | Every feature an extension could use gets a contract in `kanso/contracts`, under semver |
| Docs | An ADR per significant decision; API reference and help centre updated with the feature |
| UX | Keyboard-first, Swedish and English, WCAG 2.2 AA, tablet width |

## Key risks

1. **Too many integrations.** Each connector brings its own edge cases. Start with two channels and one carrier aggregator, and build the framework (M2) first.
2. **Overselling once stock leaves Kanso's view.** Reservation is sound inside Kanso; channel stock pushes (M4) and several locations (M6) are where it can break.
3. **Retrofitting the money model.** Adding tax and discounts after connectors exist means migrating imported orders. M3 therefore comes before M4.
4. **Replacing the reservation column.** Moving to a Reservation table (M6) touches reservation, shipments and order editing at once. It needs its own ADR and a migration rehearsed on production-sized data.
5. **Scope creep into WMS, ERP or PIM.** The fulfillment workspace (M5) and purchase orders (M6) are where this pressure is highest.

## References to the previous roadmap

ADRs and code comments that say "Phase N" refer to the roadmap this one replaces. It is in the git history (`git show 9c18105:ROADMAP.md`).

| Previous phase | Now |
|---|---|
| Phase 0: Foundations | Done, except the deploy target (M1) |
| Phase 1: MVP, core order flow | Done; see [Where Kanso stands](#where-kanso-stands) |
| Phase 2: Integrations and automation | M2 and M4 |
| Phase 3: Multi-location and returns | M6 and M7 |
| Phase 4: Insights and scale | M8 and M9 |
| Phase 5: Platform and growth | M5 (picking), M9 (provisioning), and [Later](#later-omnichannel-and-growth) |
