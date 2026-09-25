# ADR-0002: One installation per customer

- Status: accepted
- Date: 2026-09-25

## Context

The early roadmap assumed a shared multi-tenant database (`tenant_id` + Postgres row-level security). ADR-0001 moves Kanso to MySQL, which has no row-level security, and Pimsen's deployment model is one installation per customer.

## Decision

Kanso follows Pimsen: each customer gets its own installation (database, Redis, bucket, containers). There is no tenant concept — never add `tenant_id`. Customer differences live in environment variables, settings rows or extensions.

Processes are stateless and configured by environment variables; nothing may depend on a specific orchestrator or cloud.

## Consequences

- Data isolation between customers is structural, not a query filter that can be forgotten.
- Each customer costs a full stack; provisioning must be automated (Phase 5).
- Cross-customer reporting, if ever needed, happens outside the installations.
