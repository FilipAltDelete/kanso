<?php

declare(strict_types=1);

namespace Kanso;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Symfony *components* rather than the full-stack skeleton, as in Pimsen: no
 * sessions, no forms, no Twig. Configuration is loaded from config/packages,
 * config/services.yaml and config/routes.yaml by MicroKernelTrait.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
