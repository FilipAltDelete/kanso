<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Support;

use Kanso\Core\Internal\Application\User\UserService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * For WebTestCase tests of the API: a fresh user with the given role, signed
 * in, and JSON requests carrying its token.
 *
 * @property KernelBrowser $client
 */
trait SignsIn
{
    private string $token = '';

    private function signInAs(string $role): string
    {
        // Unique per test: the login rate limiter is keyed by email.
        $email = strtolower(str_replace('ROLE_', '', $role)).'-'.bin2hex(random_bytes(4)).'@example.com';
        $users = static::getContainer()->get(UserService::class);
        \assert($users instanceof UserService);
        $users->create($email, 'secret', [$role]);

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => 'secret'], \JSON_THROW_ON_ERROR));
        $token = $this->json()['accessToken'] ?? null;
        \assert(\is_string($token));
        $this->token = $token;

        return $email;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function api(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : $this->json();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    private function responseStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}
