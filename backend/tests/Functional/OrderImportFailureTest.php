<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Kanso\Core\Internal\Application\Catalog\CatalogService;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Inventory\Address;
use Kanso\Core\Internal\Domain\Order\Order;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One order's transaction failing partway through an import (a lost race on
 * the external reference, say) closes Doctrine's entity manager, and
 * DoctrineTransaction resets it (ADR-0015). The orders after it must still
 * be created, although the channel, products and location they use were
 * first loaded by the entity manager that was closed.
 */
final class OrderImportFailureTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Every request in the test uses this kernel, with the listener below.
        $this->client->disableReboot();
        $this->signInAs(Role::OPERATOR);

        $catalog = static::getContainer()->get(CatalogService::class);
        self::assertInstanceOf(CatalogService::class, $catalog);
        $catalog->createLocation('WH1', 'Main', new Address());
        $catalog->createProduct('TEE-1', 'T-shirt', null, null);

        // Fails the flush that writes order RACE-1, as a unique-key race on its reference would.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof Order && 'RACE-1' === $entity->externalReference()) {
                        throw new ConcurrentModification('Duplicate entry for key uq_sales_order_channel_reference');
                    }
                }
            }
        });
    }

    public function testTheOrdersAfterAFailedOneAreStillCreated(): void
    {
        $csv = "orderReference;customerName;shippingLine1;shippingPostalCode;shippingCity;shippingCountry;sku;quantity;unitPrice\n"
            ."WEB-1;Anna;Storgatan 1;111 22;Stockholm;SE;TEE-1;1;100\n"
            ."RACE-1;Bo;Kungsgatan 2;411 19;Göteborg;SE;TEE-1;1;100\n"
            ."WEB-2;Cia;Vägen 3;123 45;Malmö;SE;TEE-1;2;100\n";

        $this->client->request('POST', '/api/order-imports', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'text/csv',
        ], content: $csv);
        $result = $this->json();

        self::assertSame(200, $this->responseStatus());
        self::assertSame([3, 2, 1], [$result['orders'], $result['created'], $result['failed']]);
        self::assertSame([[3, 'RACE-1', 'orderReference', 'taken']], array_map(static fn (array $error): array => [$error['row'], $error['reference'], $error['field'], $error['code']], $result['errors']));

        // WEB-2 came after the failure and uses the same channel, product and location.
        $orders = $this->api('GET', '/api/orders?q=WEB-2');
        self::assertSame(1, $orders['totalItems']);
        $order = $this->api('GET', '/api/orders/'.$orders['member'][0]['id']);
        self::assertSame([['TEE-1', 2]], array_map(static fn (array $line): array => [$line['sku'], $line['quantity']], $order['lines']));
        self::assertSame('WH1', $order['location']['code']);

        // Nothing was written twice: one product, one location, still.
        self::assertSame(1, $this->api('GET', '/api/products?q=TEE-1')['totalItems']);
        self::assertSame(1, $this->api('GET', '/api/locations')['totalItems']);
    }
}
