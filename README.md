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

The `proxy` container routes `/` to the frontend and `/api` and `/health` to the API.

## Everyday commands

```sh
make help                # everything below and more
make test                # backend suite (needs `make up`)
make lint                # php-cs-fixer, PHPStan level 8, deptrac, ESLint
make front-test          # Vitest
make user EMAIL=ops@example.com PASSWORD=… ROLE=ROLE_OPERATOR
make project-test        # the customer project skeleton's tests (make project-install first)
make logs / down / reset
```

## API

- `POST /api/auth/login` → access token (15 min) in the body, refresh token as an HttpOnly cookie
- `POST /api/auth/refresh`, `POST /api/auth/logout`, `GET /api/auth/me`
- `GET /health/live`, `GET /health/ready`
- `GET /api/docs.json` — OpenAPI document (authenticated)

Errors are RFC 7807 problem responses (`application/problem+json`).
