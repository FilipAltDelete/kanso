<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Catalog\Product;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\Location;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The version check in InventoryService catches an operator working from an
 * old page. This proves the other half: two writers that both passed that
 * check at the same moment cannot both save, because MySQL applies the
 * UPDATE only where the version is still the one they read.
 */
final class OptimisticLockTest extends KernelTestCase
{
    public function testAWriteBasedOnAVersionSomeoneElseAlreadyChangedIsRefused(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $transaction = self::getContainer()->get(TransactionInterface::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertInstanceOf(TransactionInterface::class, $transaction);
        self::assertInstanceOf(Connection::class, $connection);

        $now = new \DateTimeImmutable();
        $level = new InventoryLevel(
            new Product('LOCK-'.bin2hex(random_bytes(3)), 'Tee', null, null, $now),
            new Location('LK-'.bin2hex(random_bytes(3)), 'Main', new Address(), $now),
            $now,
        );
        $transaction->run(static function () use ($em, $level): void {
            $em->persist($level->product());
            $em->persist($level->location());
            $em->persist($level);
            $level->adjustBy(10, new \DateTimeImmutable());
        });
        self::assertSame(1, $level->version());

        // Another process adjusts the same level after we read it.
        $connection->executeStatement('UPDATE inventory_level SET on_hand = 3, version = version + 1 WHERE id = ?', [$level->id()->toBinary()]);

        try {
            $transaction->run(static fn () => $level->adjustBy(-2, new \DateTimeImmutable()));
            self::fail('Saving over a change we never saw must be refused.');
        } catch (ConcurrentModification) {
        }

        self::assertSame(
            ['on_hand' => 3, 'version' => 2],
            array_map(intval(...), (array) $connection->fetchAssociative('SELECT on_hand, version FROM inventory_level WHERE id = ?', [$level->id()->toBinary()])),
            'The other writer\'s change stands.',
        );
    }
}
