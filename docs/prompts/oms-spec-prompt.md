# Prompt: Generate the Kanso OMS Specification

Use this prompt once to produce `docs/OMS-SPEC.md`. It is not loaded automatically; paste or reference it when you want the spec (re)generated.

---

[CONTEXT]
Kanso OMS is a **web-based Order Management System** for e-commerce and retail merchants, built on **the same tech stack and deployment model as Pimsen** (`../pimsen`, see its `CLAUDE.md` and `docs/tech-stack/`): PHP 8.4 + Symfony + API Platform, React 19 + Vite (plain JavaScript), MySQL 8.4, Redis, S3-compatible storage, Symfony Messenger, and one installation per customer. Operators use it entirely in the browser; integrations use the same public API as the web UI. The phased delivery plan is in `ROADMAP.md` — the spec describes the full target system, but must tag every capability with the roadmap phase that delivers it.

[OBJECTIVE]
Write `docs/OMS-SPEC.md`: a technical and functional specification that serves as the baseline for architecture decisions, backlog creation, and acceptance criteria.

[REQUIRED STRUCTURE]

1. **Executive Summary**
   - The role of the OMS in the merchant's stack (sales channels, payment providers, carriers, WMS/3PL, ERP/accounting).
   - Core business goals: eliminate overselling, reduce fulfillment cost, meet delivery SLAs.
   - Explicit non-goals: Kanso is not a WMS, ERP, or storefront.

2. **Users & Roles**
   - Personas (merchant admin, warehouse operator, customer service, integration developer) and their key browser workflows.
   - Role/permission matrix.

3. **Core Capability Matrix** (table: capability | description | key requirements | roadmap phase)
   - Order lifecycle & state machine, including exception handling (holds, fraud checks, backorders, cancellations).
   - Real-time inventory (on hand / reserved / available per location, reservation rules, channel sync).
   - Fulfillment (pick lists, packing slips, labels, partial and split shipments).
   - Distributed order routing (proximity, cost, stock, priority).
   - Omnichannel fulfillment (BOPIS, ship-from-store) — later phase.
   - Returns & reverse logistics (returns portal, restock, refunds).
   - Integrations (channel connectors, carriers, webhooks, public API).
   - Reporting & analytics.

4. **Web Application Requirements**
   - Browser support (latest two versions of Chrome, Edge, Firefox, Safari); responsive down to tablet for warehouse use.
   - UI patterns: data-heavy tables (filter, sort, bulk actions, saved views), keyboard shortcuts, printable documents (PDF).
   - Live updates in the UI for order and stock changes (TanStack Query refetch to start; push via SSE/Mercure is an open question given stateless PHP-FPM).
   - Performance budgets (e.g. initial load < 2.5 s LCP, list views < 500 ms API p95).
   - Accessibility target (WCAG 2.2 AA).
   - Internationalization: Swedish and English UI; multi-currency (SEK, EUR, NOK, DKK); time zones.
   - Web security: JWT auth (no server sessions), API keys for integrations, CSRF/XSS protection, CSP, 2FA, SSO (later phase), OWASP ASVS level 2.

5. **Architecture**
   - System context diagram (Mermaid): browser ↔ Nginx proxy ↔ React frontend / API Platform API ↔ MySQL, Redis, S3, Messenger workers ↔ external systems.
   - Deployment model: one installation per customer (no tenant concept), stateless containers configured by env vars — as in Pimsen.
   - Map every component to the Pimsen stack; do not introduce new technologies without flagging them as `> **Open question:**`.
   - Event-driven vs. batch sync: which data flows are real-time and which are scheduled.
   - Audit trail: every order/inventory state change is persisted as an event.
   - Reliability: idempotency, retries, dead-letter queues, backups, target uptime.

6. **Data Model Overview**
   - Core entities and relationships (Mermaid ER diagram): User, Order, OrderLine, Customer, Product/SKU, Location, InventoryLevel, Reservation, Shipment, Return, Channel.

7. **Key Performance Indicators**
   - Business KPIs: order cycle time, perfect order rate, inventory accuracy %, split order ratio, return processing time.
   - Platform KPIs: API latency, sync lag per integration, error rate, uptime.

8. **Compliance**
   - GDPR (data export, deletion, retention), PCI scope (no card data stored — delegate to payment providers).

[OUTPUT STYLE]
- Technical, precise, actionable; no marketing language.
- Structured Markdown, tables for matrices, Mermaid for diagrams.
- Mark open decisions as `> **Open question:** ...` rather than inventing answers.
