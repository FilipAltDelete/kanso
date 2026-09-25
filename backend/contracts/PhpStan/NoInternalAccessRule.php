<?php

declare(strict_types=1);

namespace Kanso\Contracts\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Only `kanso/contracts` is public API (docs/extensions.md, rule 4).
 *
 * Reaching into `Kanso\Core\Internal` costs nothing today and breaks the
 * customer's installation on the next core upgrade, when an internal signature
 * changes. A missing contract is a contracts change proposed upstream, not an
 * import.
 *
 * @implements Rule<FullyQualified>
 */
final class NoInternalAccessRule implements Rule
{
    private const string INTERNAL = 'Kanso\\Core\\Internal\\';

    /** The core is allowed its own internals; everything else is not. */
    private const string CORE = 'Kanso\\Core';

    public function getNodeType(): string
    {
        return FullyQualified::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->toString();

        if (!str_starts_with($name, self::INTERNAL)) {
            return [];
        }

        $namespace = $scope->getNamespace() ?? '';
        if (self::CORE === $namespace || str_starts_with($namespace, self::CORE.'\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                '"%s" is internal to the core and may change in any release. Extensions use Kanso\Contracts only (docs/extensions.md).',
                $name,
            ))
                ->identifier('kanso.noInternalAccess')
                ->build(),
        ];
    }
}
