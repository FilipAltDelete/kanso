<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Application\Document\GenerateDocument;
use Kanso\Core\Internal\Application\Document\GenerateDocumentHandler;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\Security\AccessTokenIssuerInterface;
use Kanso\Core\Internal\Domain\Storage\ObjectStorageInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Pick lists and packing slips end to end: requested over the API, rendered
 * by the worker's handler into the test bucket of the compose MinIO, and
 * handed out as a signed link.
 */
final class DocumentApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $operator;
    private string $viewer;

    /**
     * Messages queued so far. The kernel resets its services, the in-memory
     * queue among them, before each request, so they are collected after each.
     *
     * @var list<Envelope>
     */
    private array $queued = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // One kernel for the whole test, so the container stays the same.
        $this->client->disableReboot();
        $this->operator = $this->token(Role::OPERATOR, 'Olle Operator');
        $this->viewer = $this->token(Role::VIEWER, 'Vera Viewer');
    }

    public function testAPickListIsQueuedRenderedAndHandedOutAsASignedLink(): void
    {
        $order = $this->createOrder();

        $queued = $this->requestDocument($order['id'], ['type' => 'pick_list', 'locale' => 'sv']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $queued['status']);
        self::assertNull($queued['downloadUrl']);
        self::assertSame($order['number'], $queued['orderNumber']);
        self::assertSame('pick-list-'.$order['number'].'.pdf', $queued['filename']);
        self::assertSame('Olle Operator', $queued['requestedBy']['name']);

        self::assertSame(1, $this->work());

        $done = $this->getDocument($queued['id']);
        self::assertResponseIsSuccessful();
        self::assertSame('done', $done['status']);
        self::assertIsString($done['downloadUrl']);
        self::assertStringStartsWith('http://localhost:19010/kanso-test/documents/'.$queued['id'].'.pdf?', $done['downloadUrl']);
        self::assertStringContainsString('X-Amz-Signature=', $done['downloadUrl']);
        self::assertStringContainsString('X-Amz-Expires=300', $done['downloadUrl']);
        self::assertStringContainsString(rawurlencode('filename="pick-list-'.$order['number'].'.pdf"'), $done['downloadUrl']);

        $pdf = stream_get_contents($this->storage()->readStream('documents/'.$queued['id'].'.pdf'));
        self::assertIsString($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(\strlen($pdf), $done['byteSize']);
    }

    public function testAnUnchangedOrderGetsTheDocumentItAlreadyHas(): void
    {
        $order = $this->createOrder();

        $first = $this->requestDocument($order['id'], ['type' => 'packing_slip']);
        $again = $this->requestDocument($order['id'], ['type' => 'packing_slip']);
        self::assertSame($first['id'], $again['id'], 'a second click while the first is queued waits for the same one');
        self::assertSame(1, $this->work());

        self::assertSame($first['id'], $this->requestDocument($order['id'], ['type' => 'packing_slip'])['id'], 'and a done one is handed back');
        self::assertNotSame($first['id'], $this->requestDocument($order['id'], ['type' => 'packing_slip', 'locale' => 'sv'])['id'], 'another language is another document');
        self::assertNotSame($first['id'], $this->requestDocument($order['id'], ['type' => 'pick_list'])['id']);
    }

    public function testAChangedOrderGetsAFreshDocument(): void
    {
        $order = $this->createOrder();
        $first = $this->requestDocument($order['id'], ['type' => 'pick_list']);

        $this->request('POST', '/api/orders/'.$order['id'].'/transitions', $this->operator, ['transition' => 'confirm', 'version' => $order['version']]);
        self::assertResponseIsSuccessful();

        $second = $this->requestDocument($order['id'], ['type' => 'pick_list']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame($order['version'] + 1, $second['orderVersion']);
    }

    public function testAViewerMayPrint(): void
    {
        $order = $this->createOrder();

        $this->request('POST', '/api/orders/'.$order['id'].'/documents', $this->viewer, ['type' => 'pick_list']);

        self::assertResponseStatusCodeSame(202);
    }

    public function testABadRequestIsAProblem(): void
    {
        $order = $this->createOrder();

        $this->request('POST', '/api/orders/'.$order['id'].'/documents', $this->operator, ['type' => 'invoice', 'locale' => 'de']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['type', 'locale'], array_column($this->json()['violations'], 'path'));

        $this->request('POST', '/api/orders/01928c6a-0000-7000-8000-000000000000/documents', $this->operator, ['type' => 'pick_list']);
        self::assertResponseStatusCodeSame(404);

        $this->request('GET', '/api/documents/01928c6a-0000-7000-8000-000000000000', $this->operator);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->work(), 'nothing was queued');
    }

    public function testADocumentTheWorkerGivesUpOnSaysSo(): void
    {
        $order = $this->createOrder();
        $queued = $this->requestDocument($order['id'], ['type' => 'pick_list']);

        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        // The last attempt: retries are spent, so the worker gives up.
        $envelope = new Envelope(new GenerateDocument($queued['id']), [new RedeliveryStamp(3)]);
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('MinIO at minio:9000 is down')));

        $failed = $this->getDocument($queued['id']);
        self::assertSame('failed', $failed['status']);
        self::assertNull($failed['downloadUrl']);
        self::assertStringNotContainsString('minio', (string) $this->client->getResponse()->getContent(), 'the reason stays out of the API');

        self::assertNotSame($queued['id'], $this->requestDocument($order['id'], ['type' => 'pick_list'])['id'], 'asking again tries again');
    }

    /** Runs what the worker would: every queued message, through its handler. Returns how many. */
    private function work(): int
    {
        $this->collectQueued();
        $handler = static::getContainer()->get(GenerateDocumentHandler::class);
        self::assertInstanceOf(GenerateDocumentHandler::class, $handler);

        $envelopes = $this->queued;
        $this->queued = [];
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(GenerateDocument::class, $message);
            $handler($message);
        }

        return \count($envelopes);
    }

    private function collectQueued(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->get() as $envelope) {
            $this->queued[] = $envelope;
            $transport->ack($envelope);
        }
    }

    /** @return array<string, mixed> */
    private function createOrder(): array
    {
        $this->request('POST', '/api/orders', $this->operator, [
            'customer' => ['name' => 'Åsa Öberg', 'email' => 'asa@example.com'],
            'shippingAddress' => ['line1' => 'Storgatan 1', 'postalCode' => '111 22', 'city' => 'Stockholm', 'countryCode' => 'SE'],
            'lines' => [
                ['sku' => 'TSHIRT-M', 'name' => 'T-shirt, M', 'quantity' => 3, 'unitPrice' => 19_950],
                ['sku' => 'SOCKS', 'name' => 'Strumpor', 'quantity' => 2, 'unitPrice' => 4_900],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $this->json();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requestDocument(string $orderId, array $body): array
    {
        $this->request('POST', '/api/orders/'.$orderId.'/documents', $this->operator, $body);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function getDocument(string $id): array
    {
        $this->request('GET', '/api/documents/'.$id, $this->operator);

        return $this->json();
    }

    private function storage(): ObjectStorageInterface
    {
        $storage = static::getContainer()->get(ObjectStorageInterface::class);
        self::assertInstanceOf(ObjectStorageInterface::class, $storage);

        return $storage;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, string $token, ?array $body = null): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $this->collectQueued();
    }

    private function token(string $role, string $name): string
    {
        $users = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $users);
        $issuer = static::getContainer()->get(AccessTokenIssuerInterface::class);
        self::assertInstanceOf(AccessTokenIssuerInterface::class, $issuer);

        return $issuer->issue($users->create('u-'.bin2hex(random_bytes(4)).'@example.com', 'secret', [$role], $name));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
