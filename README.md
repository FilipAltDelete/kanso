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
| `docker/` | Images: `api`, `worker`, `web` and the reference `proxy` |
| `compose.yaml` | Local reference deployment; `compose.prod.yaml` runs the images as shipped; `compose.project.yaml` runs it from `project/` |

The `proxy` container routes `/` to the frontend and `/api` and `/health/live` to the API, and sets the security headers ([below](#tls-and-the-proxy)). Readiness (`/health/ready`) and Prometheus metrics (`/metrics`) are for the platform and the scraper, which reach the API container directly; the proxy answers 404 for both. Metrics, tracing and the shipped dashboards are described in [`observability/README.md`](observability/README.md); `docker compose --profile observability up -d` starts a local Prometheus, Grafana and Jaeger.

## TLS and the proxy

The `proxy` container is an installation's only entry point. It sets the security headers and the Content Security Policy (`docker/proxy/`, [ADR-0018](docs/adr/0018-security-headers-and-proxy-trust.md)) and serves plain HTTP on `KANSO_PORT`. TLS is terminated in front of it by whatever the host already runs: a load balancer, Caddy, Traefik or a CDN.

The API needs each request's real scheme and client address. The refresh cookie is `Secure` only over HTTPS, and the login limiter counts attempts per address. Two settings carry them through:

| Variable | Set on | Default | What it lists |
|---|---|---|---|
| `PROXY_TRUSTED_PROXIES` | `proxy` | empty | Where a TLS terminator in front of the proxy connects from: addresses or CIDR ranges, comma-separated, or `PRIVATE_SUBNETS`. Only these peers may say which client and scheme a request came from. |
| `TRUSTED_PROXIES` | `api`, `worker` | `PRIVATE_SUBNETS` | Who the API believes about the same: the proxy, at whatever address Docker gives it. |

Behind a TLS terminator:

1. Forward to the proxy's port with `X-Forwarded-For` and `X-Forwarded-Proto` set. Most terminators do this by default; with Nginx it is `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` and `proxy_set_header X-Forwarded-Proto $scheme;`.
2. Set `PROXY_TRUSTED_PROXIES` in `.env` to the addresses the terminator connects from, e.g. `PROXY_TRUSTED_PROXIES=10.0.0.5`. A terminator on the same compose network gets its address from Docker: use `PRIVATE_SUBNETS`.
3. Recreate the proxy: `docker compose up -d proxy`.
4. Check: `curl -sI https://oms.example.com/ | grep -i strict-transport-security` prints `max-age=31536000`. If it prints nothing, the proxy does not trust the terminator and still sees plain HTTP.

From anyone not listed, the proxy ignores these headers. It replaces them before a request reaches the API, so a browser that reaches the proxy directly cannot claim HTTPS or another address. `TRUSTED_PROXIES` can stay as it is, because the API only hears from the proxy. Do not publish the API container's port, since the API believes forwarded headers from any private address.

HSTS (one year, this host only) is sent only when the request arrived over TLS, so a plain-HTTP installation (development, a LAN) is never pinned to HTTPS.

The Vite dev server needs a looser policy (inline scripts and styles, the hot-reload websocket), which `compose.yaml` mounts from `docker/proxy/csp-dev.conf`. The proxy image and `compose.prod.yaml` use the strict one. A deployment that uses its own reverse proxy instead of the `proxy` image must send the same headers and forward the same way (`docker/proxy/security-headers.conf`, `csp.conf`, `api.conf`).

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
- `POST /api/auth/password` (`{"currentPassword", "newPassword"}`, at least 8 characters): changes the signed-in user's own password, ends their other sessions and answers with new tokens, like a login
- Users: an admin adds people (with a first password to pass on; no email is sent), changes their role, sets a forgotten password and deactivates or activates them under Settings → Users, or through `/api/users` (`docs/adr/0017`). There is always at least one active admin. The console's `kanso:user:create` still works.
- Integrations authenticate with an API key instead: `X-Api-Key: kso_…` or `Authorization: Bearer kso_…`.
  An admin creates and revokes keys under Settings → API keys (`/api/api-keys` in the API), or from the console:
  `php bin/console kanso:api-key:create "Shopify sync" --role=ROLE_OPERATOR [--expires="+90 days"] [--created-by=admin@example.com]`
  (the key is printed once; only its hash is stored) and revoke it with `kanso:api-key:revoke <id>`.
  A key has one role — Operator or Viewer, never Admin — and 3,000 requests a minute.
- `GET /health/live`, `GET /health/ready` (readiness on the API container only, not through the proxy)
- `GET/POST /api/customers` (`?q=` searches name and email, `?sort=name,-createdAt`, `?page=`, `?itemsPerPage=`), `GET/PATCH /api/customers/{id}` (merge patch), `GET /api/customers/{id}/history`; a customer's orders are `GET /api/orders?customer={id}`. Reading needs a sign-in; writing the operator role.
- `POST /api/orders/{id}/documents` (`{"type": "pick_list" | "packing_slip", "locale": "sv" | "en"}`) queues a PDF; poll `GET /api/documents/{id}` until `status` is `done`, then open its `downloadUrl` (signed, five minutes). Needs a running worker (`docs/adr/0007`).
- `GET /api/dashboard?timeZone=Europe/Stockholm`: orders placed and shipped today (the caller's calendar day), orders awaiting fulfillment, orders by status, and stock-outs.
- `GET /api/docs.json` — OpenAPI document (authenticated)

Errors are RFC 7807 problem responses (`application/problem+json`).
