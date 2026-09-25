# ADR-0001: Reuse Pimsen's tech stack

- Status: accepted
- Date: 2026-09-25

## Context

Kanso OMS is built by the same team that builds Pimsen. Running two stacks would double the operational knowledge, tooling and hiring surface.

## Decision

Kanso uses Pimsen's stack and follows Pimsen's ADRs unless a Kanso ADR says otherwise:

- PHP 8.4, Symfony 7.4 components (not full-stack), Doctrine, API Platform 4, Nginx + PHP-FPM
- Symfony Messenger on the Doctrine transport (a MySQL table, no broker)
- JWT (LexikJWTAuthenticationBundle) with a rotating refresh cookie; no server sessions
- React 19 + Vite in plain JavaScript (no TypeScript), TanStack Router/Query/Virtual, Tailwind 4, Zod at the API boundary
- MySQL 8.4, Redis 7, S3-compatible storage
- PHPUnit, PHPStan level 8, deptrac, php-cs-fixer; ESLint + Vitest

The Phase 0 scaffold copies Pimsen's Docker, Makefile, CI and auth patterns.

## Consequences

- Operational runbooks and tooling carry over between the products.
- Pimsen's constraints come along: no TypeScript, stateless PHP-FPM (live push to the browser needs a separate decision), MySQL rather than Postgres.
