<?php

declare(strict_types=1);

namespace Kanso\Core;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The core, as a Symfony bundle a customer project registers (ADR-0003, as in
 * Pimsen).
 *
 * This class is the only thing in `Kanso\Core` that is not `Internal`, because
 * a project has to name it in `config/bundles.php`. All it does is carry the
 * core's own configuration into that project: services, and defaults for the
 * framework, Doctrine, Messenger, security, API Platform and the rest.
 *
 * Prepended, not loaded: prepended configuration sits *underneath* the
 * project's own, so a customer can raise a rate limit or add a transport in
 * their `config/packages/` without copying the core's file.
 */
final class KansoCoreBundle extends AbstractBundle
{
    /** The package root, not `src/`: the configuration and migrations live there. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import($this->getPath().'/config/services.yaml');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Where the *core* is, which is not where the project is: mappings,
        // migrations and API resources belong to the package.
        $builder->setParameter('kanso.core_dir', $this->getPath());

        // A stable order, so an import that depends on another never depends
        // on the filesystem's.
        $files = glob($this->getPath().'/config/packages/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            $container->import($file);
        }
    }
}
