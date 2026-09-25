# kanso/contracts

The public API of Kanso. Everything here is what a customer extension is written
against; everything in `Kanso\Core\Internal\*` is not, and importing it from a
bundle is a PHPStan error (see `docs/extensions.md`).

**This package depends on nothing** except PHP: not the core, not Symfony, not
Doctrine. That makes it safe for a customer project to pin. Upgrading it never
drags a framework version along.

The one exception is `PhpStan/NoInternalAccessRule.php`, which references
PHPStan, a **dev** requirement. The class is only loaded by PHPStan itself, so a
`--no-dev` install gets an inert file. Include `extension.neon` from a project's
`phpstan.neon` to turn it on.

Semver is strict here, unlike in the core:

* A new interface method is a **major** change. An optional DTO constructor
  argument is not.
* Anything to be removed is deprecated for one minor first.
* The core requires this package with `^0.1`; only the core may use its own
  internals.

**Status:** empty apart from the PHPStan rule. Contracts are added as Phase 1
and 2 build the order, inventory and integration APIs that extensions need.
