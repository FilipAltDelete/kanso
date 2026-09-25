<?php

declare(strict_types=1);

namespace Acme;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Acme's kernel. The core is a bundle, so this is the ordinary Symfony one:
 * the project owns `config/`, `var/` and its own code, and nothing else.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
