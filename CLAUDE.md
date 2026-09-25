# Kanso OMS

A **web-based Order Management System** for e-commerce and retail merchants. It receives orders from sales channels, manages inventory across locations, and drives fulfillment and returns. Operators work entirely in the browser.

Kanso uses **the same tech stack and deployment model as Pimsen** (`../pimsen`). When a stack or infrastructure question comes up, follow Pimsen's choices and ADRs (`../pimsen/docs/06-decisions.md`, `../pimsen/docs/tech-stack/`) unless a Kanso ADR says otherwise.

- Roadmap: `ROADMAP.md` — **current phase: Phase 0 (Foundations), scaffold done** (status in the Phase 0 section there)
- Decisions: `docs/adr/` · Domain model draft: `docs/domain-model.md` · Setup: `README.md`
- Spec: `docs/OMS-SPEC.md` (not yet generated — see `docs/prompts/oms-spec-prompt.md`)

## Layout
- `backend/` — the core, Composer package `kanso/core`: `Kanso\Core\KansoCoreBundle` plus `Kanso\Core\Internal\*`. Layers (enforced by deptrac): `Api`/`Cli` → `Application` → `Domain` ← `Infrastructure`. Api and Cli use Domain interfaces; Infrastructure implements them; aliases live in `config/services.yaml`. The bundle prepends `config/packages/*`, so core paths use `%kanso.core_dir%`, not `%kernel.project_dir%`.
- `backend/contracts/` — `kanso/contracts`, the public API customer extensions code against. Depends on nothing; strict semver.
- `project/` — customer project skeleton (sample `AcmeBundle`). **Customer-specific code goes in project bundles, never in the core** (`docs/extensions.md`, ADR-0003).
- `frontend/` — React app: `src/api` (client + Zod schemas), `src/app` (shell, router), `src/features/<area>`, `src/components/ui`, `src/lib` (incl. `i18n.jsx`).
- `docker/`, `compose.yaml` — images and the reference deployment. The `proxy` routes `/` to the frontend, `/api` and `/health` to the API. Local port **8090** (Pimsen uses 8080).

## Scope rules
- Build only what the current roadmap phase needs. Later-phase features (order routing, BOPIS, B2B, analytics) are out of scope unless explicitly asked for.
- Kanso is an OMS, not a WMS, ERP, or storefront — integrate with those systems, don't rebuild them.
- If a request conflicts with `ROADMAP.md`, point out the conflict before implementing.

## Domain
- **Core entities:** User, Order, OrderLine, Customer, Product/SKU, Location, InventoryLevel, Reservation, Shipment, Return, Channel.
- **Order states:** `pending → confirmed → allocated → picking → packed → shipped → delivered`, plus `cancelled` and `on_hold`. Transitions go through the state machine only — never set status directly.
- **Inventory:** `available = on_hand − reserved`. Stock is reserved when an order is confirmed, in the same transaction as the state change.
- **Audit trail:** every order and inventory change writes an event (who, what, when, before/after).
- **Money:** store amounts as integer minor units with an ISO 4217 currency code; never floats.

## Stack (same as Pimsen)
- **Backend:** PHP 8.4, Symfony 7 components (not full-stack), Doctrine ORM/DBAL + migrations, API Platform 4 (REST + OpenAPI), Nginx + PHP-FPM.
- **Jobs:** Symfony Messenger on the Doctrine transport (a MySQL table, no broker); Symfony Scheduler for recurring work (channel syncs, stock pushes).
- **Auth:** JWT (LexikJWTAuthenticationBundle) for the web UI, API keys for integrations; Symfony rate limiter on the public API.
- **Frontend:** React 19 + Vite in plain JavaScript — **no TypeScript**; TanStack Router, Query and Virtual; Tailwind CSS 4 with shadcn-style primitives; lucide-react icons; Zod at the API boundary.
- **Storage:** MySQL 8.4, Redis 7 (cache, locks, metrics), S3-compatible object storage (MinIO locally) for labels, packing slips and import files.
- **Observability:** OpenTelemetry tracing, Prometheus metrics, Grafana; Monolog logging.
- **Quality:** PHPUnit, PHPStan level 8, deptrac, php-cs-fixer; ESLint (incl. jsx-a11y) and Vitest + Testing Library on the frontend.
- **Deployment:** Docker images (api, worker, frontend) + Nginx proxy, configured by env vars; Docker Compose for dev. Infrastructure-agnostic — nothing may depend on a specific orchestrator or cloud.

## Architecture
- **Web app + API:** the browser UI consumes the same public REST API as integrations — no private UI-only endpoints. The OpenAPI document is the contract.
- **One installation per customer, no tenant concept** (as in Pimsen). Never add `tenant_id`. Customer differences go in env vars, settings rows, or extensions.
- **Stateless processes:** no PHP sessions, no local disk beyond a request, no in-process state across requests. Workers reset between messages.
- **Integrations:** run as Messenger jobs with retries, idempotency keys, and a failure transport.
- **Writes:** each order or stock change is one database transaction; events are dispatched after commit.
- **Live UI updates:** TanStack Query polling/refetch to start with. Push (SSE/Mercure) is an open decision — it must fit stateless PHP-FPM.

## Web application standards
- **Browsers:** latest two versions of Chrome, Edge, Firefox, Safari. Responsive down to tablet width (warehouse use).
- **UI:** the app is table-heavy — lists need filtering, sorting, pagination, bulk actions, and saved views. Support keyboard shortcuts for operator workflows.
- **Accessibility:** WCAG 2.2 AA — semantic HTML, labelled form controls, visible focus, sufficient contrast.
- **i18n:** Swedish and English UI from day one; no hard-coded user-facing strings. Format dates, numbers, and currency by locale; store timestamps in UTC.
- **Security:** JWT auth (no server sessions); CSRF protection where cookies are used; strict Content Security Policy; validate all input on the server; never store card data (delegate to payment providers).
- **Performance:** target LCP < 2.5 s and API p95 < 500 ms for list views.

## Commands
Everything runs in containers; there is no host PHP. `make help` lists all targets.

```
make install / up / down / reset     # deps; JWT keys + start stack (:8090); stop; delete volumes
make test [ARGS="--filter …"]        # phpunit against kanso_test (needs the stack up)
make lint                            # php-cs-fixer + phpstan (level 8) + deptrac + eslint
make front-test / front-lint / front-build
make user EMAIL=… PASSWORD=… ROLE=ROLE_OPERATOR
make project-install / project-check / project-test / project-up   # the project/ skeleton
```

Console: `docker compose run --rm --no-deps tools php bin/console …` — `kanso:install`, `kanso:user:create`, `kanso:health`, `kanso:jwt:generate-keys`.

- First login on a fresh installation is **admin / admin** unless `KANSO_ADMIN_EMAIL`/`KANSO_ADMIN_PASSWORD` are set (required in prod).
- Install frontend packages with `make front-install`, never host `npm install`: the container is Alpine and needs its own native binaries.

## Conventions
- Tests are required for the order state machine, inventory reservation, and anything touching money.
- Every user-facing string goes through `t()` with keys in both `sv` and `en` (`frontend/src/lib/i18n.jsx`; a test checks the key sets match).
- API errors are RFC 7807 problem responses: throw an `Application\Exception\ApplicationException` subclass and the listener maps it.
- The test suite's `DATABASE_URL` is forced to `kanso_test` in `phpunit.xml.dist`; keep it forced.
- Database changes go through hand-written Doctrine migrations only — never `doctrine:migrations:diff` or `doctrine:schema:update`.
- Record significant technical decisions as ADRs in `docs/adr/`.
- _TBD: code style, commit format, branching._
