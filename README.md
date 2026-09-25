# Kanso OMS

A web-based Order Management System for e-commerce and retail merchants, on the same stack and deployment model as Pimsen: PHP 8.4 + Symfony + API Platform, React 19 + Vite, MySQL 8.4, Redis, S3-compatible storage — one installation per customer.

- Plan: [ROADMAP.md](ROADMAP.md) · Working rules: [CLAUDE.md](CLAUDE.md)
- Decisions: [docs/adr/](docs/adr/) · Domain model draft: [docs/domain-model.md](docs/domain-model.md)

## Getting started

Requires Docker and `make`; there is no host PHP or Node.

```sh
cp .env.example .env     # optional: ports and first-admin credentials
make install             # composer + npm, inside the containers
make up                  # JWT keys into .env, then the stack
```

Open <http://localhost:8090> and sign in as **admin / admin** (development default; set `KANSO_ADMIN_EMAIL` / `KANSO_ADMIN_PASSWORD` before the first start to seed real credentials — required in prod).

## Layout

| Path | What |
|---|---|
| `backend/` | The core (`kanso/core`), a Symfony bundle. `src/{Domain,Application,Infrastructure,Api,Cli}`, layers enforced by deptrac |
| `backend/contracts/` | `kanso/contracts`: the public API for customer extensions |
| `project/` | Customer project skeleton: the core plus a customer's own bundles, e.g. integrations ([docs/extensions.md](docs/extensions.md)) |
| `frontend/` | React app (plain JavaScript). `src/{api,app,components,features,lib}` |
| `docker/` | Images (`api`, `worker`, `web`) and the reference proxy |
| `compose.yaml` | Local reference deployment; `compose.prod.yaml` runs the images as shipped; `compose.project.yaml` runs it from `project/` |

The `proxy` container routes `/` to the frontend and `/api` and `/health` to the API. The API container also serves Prometheus metrics at `/metrics`, which the proxy does not route. Metrics, tracing and the shipped dashboards are described in [`observability/README.md`](observability/README.md); `docker compose --profile observability up -d` starts a local Prometheus, Grafana and Jaeger.

## Everyday commands

```sh
make help                # everything below and more
make test                # backend suite (needs `make up`)
make lint                # php-cs-fixer, PHPStan level 8, deptrac, ESLint
make front-test          # Vitest
make user EMAIL=ops@example.com PASSWORD=… ROLE=ROLE_OPERATOR
make project-test        # the customer project skeleton's tests (make project-install first)
make observability-check # promtool on the alert and recording rules
make e2e                 # end-to-end tests in Chromium: the built frontend + the running API (make up first)
make logs / down / reset
```

## API

- `POST /api/auth/login` → access token (15 min) in the body, refresh token as an HttpOnly cookie
- `POST /api/auth/refresh`, `POST /api/auth/logout`, `GET /api/auth/me`
- Integrations authenticate with an API key instead: `X-Api-Key: kso_…` or `Authorization: Bearer kso_…`.
  Create one with `php bin/console kanso:api-key:create "Shopify sync" --role=ROLE_OPERATOR [--expires="+90 days"] [--created-by=admin@example.com]`
  (the key is printed once; only its hash is stored) and revoke it with `kanso:api-key:revoke <id>`.
  A key has one role — Operator or Viewer, never Admin — and 3,000 requests a minute.
- `GET /health/live`, `GET /health/ready`
- `GET/POST /api/customers` (`?q=` searches name and email, `?sort=name,-createdAt`, `?page=`, `?itemsPerPage=`), `GET/PATCH /api/customers/{id}` (merge patch), `GET /api/customers/{id}/history`; a customer's orders are `GET /api/orders?customer={id}`. Reading needs a sign-in; writing the operator role.
- `POST /api/orders/{id}/documents` (`{"type": "pick_list" | "packing_slip", "locale": "sv" | "en"}`) queues a PDF; poll `GET /api/documents/{id}` until `status` is `done`, then open its `downloadUrl` (signed, five minutes). Needs a running worker (`docs/adr/0007`).
- `GET /api/dashboard?timeZone=Europe/Stockholm`: orders placed and shipped today (the caller's calendar day), orders awaiting fulfillment, orders by status, and stock-outs.
- `GET /api/docs.json` — OpenAPI document (authenticated)

Errors are RFC 7807 problem responses (`application/problem+json`).
