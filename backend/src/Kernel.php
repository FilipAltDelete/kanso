<?php

declare(strict_types=1);

namespace Kanso\Core\Internal;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The reference installation's kernel. Symfony *components* rather than the
 * full-stack skeleton, as in Pimsen: no sessions, no forms, no Twig.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Nothing. All of this installation's configuration comes from
     * `KansoCoreBundle`, exactly as it does for a customer project (project/):
     * the reference installation gets no private path into the core, or the
     * path a customer takes would be the one nobody tests.
     */
    protected function configureContainer(ContainerConfigurator $container): void
    {
    }
}
