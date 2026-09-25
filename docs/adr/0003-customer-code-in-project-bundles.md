# ADR-0003: Customer-specific code lives in project bundles

- Status: accepted
- Date: 2026-09-25

## Context

Customers need their own code: integrations with their ERP, WMS or webshop, custom exports, extra endpoints. ADR-0002 rules out tenant switches in the core, and settings rows only cover differences that are data, not behaviour. Pimsen solves this with a package split (Pimsen ADR-016, ADR-027, `../pimsen/docs/07-extensions.md`).

## Decision

Kanso uses Pimsen's arrangement:

- **`kanso/core`** (`backend/`) is a Composer package. `Kanso\Core\KansoCoreBundle` is its only public class; everything else is `Kanso\Core\Internal\*`. The bundle prepends the core's configuration, so a project can override any of it in its own `config/packages/`.
- **`kanso/contracts`** (`backend/contracts/`) is the public API extensions code against: interfaces, DTOs and events, depending on nothing but PHP, versioned with strict semver.
- **A customer installation is a Composer project** that requires the core and registers its own bundles. `project/` is the skeleton, with a sample `AcmeBundle`. The reference installation in `backend/` boots through the same bundle, so the path a customer takes is the tested one.
- Extensions follow the rules in `docs/extensions.md`. Using `Kanso\Core\Internal` is a PHPStan error (`vendor/kanso/contracts/extension.neon`). Extension jobs run on the core's `ext` Messenger transport.

Not built yet (Pimsen has them): a published test kit (`kanso/core-tests`), a runtime rules check (`kanso:extensions:check`), frontend plugins, and a project image build. They are added when the first real customer extension needs them.

## Consequences

- The core never gets customer branches, and customer code upgrades with the core as long as it uses only contracts.
- Contracts have to be designed deliberately. Each Phase 1 and 2 feature that integrations touch (orders, inventory, shipments) needs a contract, not just an internal service.
- Core classes moved to `Kanso\Core\Internal\*`. The one existing migration's class name changed, so databases migrated before this ADR need their `doctrine_migration_versions` row renamed. Nothing had been deployed, so only dev databases are affected.
