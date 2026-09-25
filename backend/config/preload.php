<?php

declare(strict_types=1);

// OPcache preloading (docker/api/php.ini): the container's own preload list,
// written by `cache:warmup` in the image build.
if (file_exists(dirname(__DIR__).'/var/cache/prod/Kanso_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/Kanso_KernelProdContainer.preload.php';
}
