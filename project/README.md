# Customer project skeleton

The shape of a customer's Kanso installation: the core as a Composer package (`kanso/core`) plus the customer's own bundles. `AcmeBundle` is a sample. A new customer's repository starts as a copy of this folder, with `Acme` renamed.

See [docs/extensions.md](../docs/extensions.md) for the rules customer code follows, and ADR-0003 for why it is set up this way.

## What is here

| Path | What |
|---|---|
| `composer.json` | Requires `kanso/core`. Here through path repositories into `../backend`; a real project pins a released version |
| `config/bundles.php` | `KansoCoreBundle` plus the customer's bundles |
| `config/routes.yaml` | The core's routes, then the customer's under `/api/ext/acme` |
| `config/packages/` | Additions to the core's configuration (here: routing Acme's messages to the `ext` transport) |
| `src/AcmeBundle/` | A sample integration: `POST /api/ext/acme/erp-sync` and `bin/console acme:erp:sync` queue a `SyncOrdersToErp` message, and a handler picks it up in the worker |
| `tests/` | Tests that use only the core's public surface (HTTP API and console commands) |

## Commands

```sh
make project-install   # composer install (the core is symlinked in from ../backend)
make project-check     # PHPStan, including the rule that forbids Kanso\Core\Internal
make project-test      # the tests, against kanso_project_test
make project-up        # run the local stack from here; `make up` switches back
```
