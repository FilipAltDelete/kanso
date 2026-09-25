<?php

declare(strict_types=1);

namespace Acme\Tests\Functional;

use Acme\AcmeBundle\Message\SyncOrdersToErp;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Only through the core's public surface: its console commands and its HTTP
 * API. Nothing here imports Kanso\Core\Internal.
 */
final class ErpSyncTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheEndpointNeedsAToken(): void
    {
        $this->client->request('POST', '/api/ext/acme/erp-sync');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAViewerMayNotQueueASync(): void
    {
        $this->client->request('POST', '/api/ext/acme/erp-sync', server: $this->signIn('ROLE_VIEWER'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->extTransport()->getSent());
    }

    public function testAnOperatorQueuesASyncOnTheExtensionTransport(): void
    {
        $this->client->request('POST', '/api/ext/acme/erp-sync', server: $this->signIn('ROLE_OPERATOR'));

        self::assertResponseStatusCodeSame(202);
        $sent = $this->extTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(SyncOrdersToErp::class, $sent[0]->getMessage());
    }

    /** @return array<string, string> */
    private function signIn(string $role): array
    {
        // Unique per test: the login rate limiter is keyed by email, and this
        // suite has no rollback between tests.
        $email = 'acme-'.bin2hex(random_bytes(4)).'@example.com';

        $kernel = $this->client->getKernel();
        $create = new CommandTester((new Application($kernel))->find('kanso:user:create'));
        $create->execute(['email' => $email, 'password' => 'secret', '--role' => [$role]]);
        $create->assertCommandIsSuccessful();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => 'secret'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $token = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['accessToken'];

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function extTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.ext');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
