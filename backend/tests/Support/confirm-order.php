<?php

declare(strict_types=1);

/*
 * One confirmation in a process of its own, for ConcurrentConfirmationTest:
 * a real second connection to MySQL, as a second PHP-FPM worker would have.
 *
 *   php tests/Support/confirm-order.php <order id> <start at, Unix time with microseconds>
 *
 * Boots the kernel first, then waits for the start time, so that every
 * process begins its transaction at the same moment. Prints `confirmed`, or
 * `conflict:<violation code>`.
 */

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Order\OrderService;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Kernel;
use Psr\Container\ContainerInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $orderId, $startAt] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$orders = $container->get(OrderService::class);
assert($orders instanceof OrderService);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $orders->transition($orderId, 'confirm', 1, new Actor('test', 'Concurrent test'));
    echo 'confirmed';
} catch (Conflict $conflict) {
    echo 'conflict:'.($conflict->violations()[0]['code'] ?? '');
}
