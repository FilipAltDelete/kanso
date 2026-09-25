# Kanso OMS: Product Roadmap

A web-based **Order Management System** for e-commerce and retail: it takes in orders from sales channels, tracks inventory, and handles fulfillment and returns.

## Guiding principles
- **The order is the core object.** Every feature either creates, changes, routes or reports on an order.
- **Every change is recorded.** Each state change on an order or stock level is logged, so the history can always be checked and replayed.
- **Build the API first.** The web UI uses the same API that integrations use.
- **Ship something useful early.** Phase 1 should let one real merchant process real orders.

---

## Phase 0: Foundations (weeks 0–4)
**Goal:** a working skeleton that can be deployed, with the basic decisions made.

- **Tech stack: same as Pimsen.** PHP 8.4 with Symfony, Doctrine and API Platform on the backend; React 19 + Vite (plain JavaScript) with TanStack and Tailwind on the frontend; MySQL 8.4, Redis and S3-compatible storage; Symfony Messenger for background jobs. Reuse Pimsen's Docker, Makefile, CI and observability setup as the starting point.
- **Deployment model: same as Pimsen.** One installation per customer, no tenant concept; everything configured by env vars.
- **Authentication:** JWT login for the web UI, API keys for integrations, and a basic role model (Admin, Operator, Viewer).
- **Infrastructure:** a CI pipeline (GitHub Actions), staging and production environments, hand-written database migrations and seed data. Logging, metrics and tracing with Monolog, Prometheus and OpenTelemetry, as in Pimsen.
- **Domain model draft:** Order, OrderLine, Customer, Product/SKU, Location/Warehouse, InventoryLevel, Shipment, Return, Channel.
- **UI shell:** navigation, layout, a design system and table components. An OMS is mostly tables, so good tables matter.

**Exit criteria:** a user can log in, see an empty dashboard, and the app deploys automatically.

**Status (2026-09-25):**
- [x] Stack, deployment model and auth as Pimsen (`docs/adr/0001`, `0002`)
- [x] Docker images (api, worker, web), Compose stack, Makefile, hand-written migrations, dev admin seeding
- [x] JWT login with rotating refresh cookie, login rate limiting, roles (Admin, Operator, Viewer)
- [x] UI shell: login, navigation, empty dashboard, Swedish/English
- [x] CI: lint, static analysis, tests, image builds (GitHub Actions)
- [x] Domain model draft (`docs/domain-model.md`)
- [ ] Deploy target: where images are pushed and which host runs staging/production (open decision)
- [ ] Metrics and tracing (Prometheus, OpenTelemetry) — only Monolog JSON logging so far
- [ ] Table component for Phase 1 lists

---

## Phase 1: MVP, core order flow (weeks 4–12)
**Goal:** one merchant can manage their full order lifecycle manually.

**Orders**
- Create orders manually and import them from CSV
- Order list with filters (status, date, channel, customer), search and pagination
- Order detail page: order lines, customer, addresses, payment status and a timeline of events
- An order state machine: `pending → confirmed → allocated → picking → packed → shipped → delivered` (plus `cancelled` and `on_hold`)
- Edit orders before fulfillment, cancel them (fully or partly), and add notes and tags

**Products and inventory**
- Product and SKU catalog (manual entry and CSV import)
- Stock levels per location: on hand, reserved, available
- Stock is reserved automatically when an order is confirmed
- Manual stock adjustments, each with a reason code

**Fulfillment (basic)**
- Pick lists and packing slips, as PDFs
- Mark orders shipped and enter the tracking number by hand
- Partial shipments

**Customers**
- Customer records with order history

**Exit criteria:** a pilot merchant runs their daily operations in Kanso.

---

## Phase 2: Integrations and automation (months 3–6)
**Goal:** orders arrive and leave automatically instead of by hand.

- **Sales channel connectors:** Shopify, WooCommerce, and Amazon or other marketplaces. Pull orders in, push fulfillment and stock updates back.
- **Carrier integrations:** a shipping aggregator (e.g. Shipmondo, nShift, EasyPost or Shippo) for rates, labels and tracking. For the Nordics, cover PostNord, DHL, Bring and Budbee.
- **Webhooks and a public REST API,** with API keys, rate limits and OpenAPI docs
- **Background jobs:** Symfony Messenger with retries, a failure transport and idempotency keys; Symfony Scheduler for recurring syncs
- **Rules engine v1:** "if X then Y" rules, for example auto-tagging, putting orders on hold for fraud or high value, or picking the shipping method
- **Notifications:** customer emails (confirmation, shipped) and internal alerts (low stock, failed sync)
- **Bulk actions:** print labels in bulk, change status in bulk

**Exit criteria:** at least 80% of the pilot merchant's orders need no manual steps.

---

## Phase 3: Multi-location and returns (months 6–9)
**Goal:** support merchants with more complicated operations.

- **Multiple warehouses and stores,** each with its own stock
- **Distributed order routing:** a split and allocation engine based on stock, distance, cost and priority
- **Split shipments,** and backorders or pre-orders
- **Returns (RMA):** a customer-facing returns portal, return reasons, restocking, and refund or exchange handling
- **Stock transfers** between locations
- **Purchase orders and inbound receiving (light):** track expected stock
- **Audit log UI:** who changed what, and when

---

## Phase 4: Insights and scale (months 9–12)
**Goal:** give merchants visibility into their operations, and harden the platform.

- **Dashboards:** order volume, fulfillment speed (SLA), backlog, return rate and channel mix
- **Inventory analytics:** stock-outs, slow-moving items, reorder suggestions
- **Exports and scheduled reports**
- **Performance:** handle 100k+ orders per installation; MySQL read replicas and a search engine if MySQL full-text search isn't enough
- **Granular permissions:** custom roles and restricting users to specific locations
- **Security and compliance:** GDPR (data export and deletion, retention rules), 2FA, SSO (SAML/OIDC), and a penetration test

---

## Phase 5: Platform and growth (12+ months)
- Integration marketplace and app framework for third parties
- Handheld and mobile picking app (a PWA with barcode scanning)
- B2B features: customer-specific price lists, credit terms, EDI
- Omnichannel: buy online and pick up in store, ship from store
- AI-assisted features: demand forecasting, anomaly and fraud detection, suggested routing rules
- Automated provisioning of new customer installations (following Pimsen's customer onboarding process)

---

## Cross-cutting work (every phase)
| Area | Practice |
|---|---|
| Testing | Unit tests on the state machine and allocation logic, contract tests on integrations, and end-to-end tests of the main flows (Playwright). PHPStan level 8, deptrac and php-cs-fixer on the backend, as in Pimsen |
| Data integrity | Transactional stock reservations, optimistic locking and idempotent imports |
| Observability | Sync-health dashboards per integration, with alerts on failed jobs |
| Docs | API reference, merchant help center and internal architecture decision records |
| UX | Keyboard-first operator workflows and a responsive layout for tablets on the warehouse floor |

## Key risks
1. **Too many integrations.** Each connector brings its own edge cases. Start with 1–2 channels and 1 carrier aggregator.
2. **Stock getting out of sync with channels (overselling).** Design the reservation and sync model carefully in Phase 1.
3. **Scope creep into warehouse management (WMS) or ERP.** Keep the OMS focused, and integrate with WMS and ERP systems instead of rebuilding them.
