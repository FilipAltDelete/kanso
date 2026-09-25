# Extensions: customer-specific code outside the core

Every installation can carry its own code (integrations, console commands, message handlers, endpoints) without it entering the Kanso core. The mechanism is the ordinary Symfony/Composer one, as in Pimsen (`../pimsen/docs/07-extensions.md`): **the core is a Composer package, a customer installation is a Composer project that depends on it, and customer code is one or more bundles in that project** (ADR-0003).

## Package layout

```
kanso/core         backend/            Symfony bundle Kanso\Core\KansoCoreBundle; the rest is Kanso\Core\Internal\*
kanso/contracts    backend/contracts/  Interfaces, DTOs and events extensions code against. Semver-stable.
project/                                The customer project skeleton, with a sample AcmeBundle

<customer>/kanso   the customer's repository, created from project/
  composer.json    requires kanso/core, plus anything the customer needs
  config/          bundles.php registers KansoCoreBundle + customer bundles; packages/ may override core config
  src/
    AcmeBundle/    customer code (namespace Acme\)
      Controller/  routes under /api/ext/<bundle>/
      Command/
      Message/, MessageHandler/
      Migrations/  customer-owned tables only (prefix ext_<bundle>_)
  tests/
```

A customer with no custom code still gets this repository. It holds their `composer.json` pin and config, and it is what the deployment builds and ships.

## Integrations

The most common reason a customer bundle exists is an integration: push Kanso data to an external system (ERP, WMS, webshop) or pull data in. The shape, shown by `AcmeBundle`:

- A **message** routed to the core's **`ext`** transport (`config/packages/messenger.yaml`). It gets its own queue in the same MySQL table, with the core's retry and failure handling, so a slow integration never holds up the core's jobs. The worker consumes `async` and `ext`.
- A **handler** that does the work and throws to have Messenger retry. It is stateless: services holding per-message state implement `ResetInterface`.
- **Triggers**: an endpoint under `/api/ext/<bundle>/`, a console command, and later a Scheduler entry or a core event.

Reading and writing orders, stock and shipments from an extension needs contracts that do not exist yet. They are added to `kanso/contracts` as Phase 1 and 2 build those APIs, and an extension never imports the core's internal services instead.

## Rules extensions must follow

1. **No tenant concept.** An installation is one customer (ADR-0002).
2. **Stateless.** No sessions, no local files beyond a request, request-state services implement `ResetInterface`, and long work goes through Messenger.
3. **Never modify core tables or entities.** Use your own `ext_<bundle>_*` tables, or react to events. Core migrations may change core tables between versions.
4. **Only `kanso/contracts` is public API.** A reference to `Kanso\Core\Internal\*` from outside the core fails PHPStan (include `vendor/kanso/contracts/extension.neon`).
5. **Respect security.** Every `/api` route is behind the core's firewall. Check roles (`#[IsGranted]`) on every endpoint.
6. **Backward-compatible migrations** (expand/contract), because extension migrations run in the same pre-deploy step as the core's.
7. **Everything the core requires:** integer minor units for money, audit events for order and stock changes, `t()` for UI strings.

Rule 4 is enforced today, in `make project-check` and in the CI "Customer project" job. The rest are checked in review until a runtime check (`kanso:extensions:check`, as in Pimsen) is built.

## Working on the skeleton

```sh
make project-install   # composer install in project/ (the core is symlinked in)
make project-check     # PHPStan with the no-internals rule, and a routing check
make project-test      # project/'s tests against kanso_project_test
make project-up        # run the local stack from project/ (make up switches back)
```

## Not built yet

These come when the first real customer extension needs them. Pimsen shows the shape of each.

- **`kanso/core-tests`**: the core's test kit and a conformance suite a project runs against its own build.
- **`kanso:extensions:check`**: boots the container and reports rule violations (unreset services, entities on core tables).
- **Frontend plugins**: customer pages and widgets compiled into the web bundle.
- **Project images**: building the api, worker and web images from a customer project. This depends on the deploy-target decision, which is deferred (ROADMAP.md, Phase 0).
