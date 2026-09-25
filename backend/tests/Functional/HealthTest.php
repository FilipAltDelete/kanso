<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testLiveAndReadyAnswerWithoutAToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health/live');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/health/ready');
        self::assertResponseIsSuccessful();
        self::assertSame(['database' => 'ok', 'redis' => 'ok'], json_decode((string) $client->getResponse()->getContent(), true)['checks']);
    }
}
