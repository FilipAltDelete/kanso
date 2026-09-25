<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Application;

use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Security\ApiKeyService;
use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Internal\Domain\User\UserStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ApiKeyServiceTest extends TestCase
{
    private MockClock $clock;
    private User $admin;
    private ApiKeyStoreInterface $keys;
    private ApiKeyService $service;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-25 12:00:00');
        $this->admin = new User('admin@example.com', [Role::ADMIN], null, $this->clock->now());
        $admin = $this->admin;

        $keys = new class implements ApiKeyStoreInterface {
            /** @var array<string, ApiKey> */
            private array $byId = [];

            public function findById(string $id): ?ApiKey
            {
                return $this->byId[$id] ?? null;
            }

            public function findByHash(string $keyHash): ?ApiKey
            {
                foreach ($this->byId as $key) {
                    if ($key->keyHash() === $keyHash) {
                        return $key;
                    }
                }

                return null;
            }

            public function save(ApiKey $key): void
            {
                $this->byId[(string) $key->id()] = $key;
            }
        };
        $this->keys = $keys;

        $users = new class($admin) implements UserStoreInterface {
            public function __construct(private readonly User $admin)
            {
            }

            public function findById(string $id): ?User
            {
                return (string) $this->admin->id() === $id ? $this->admin : null;
            }

            public function findByEmail(string $email): ?User
            {
                return $this->admin->email() === $email ? $this->admin : null;
            }

            public function save(User $user): void
            {
            }

            public function count(): int
            {
                return 1;
            }
        };

        $this->service = new ApiKeyService($keys, $users, $this->clock);
    }

    public function testCreateReturnsThePlainKeyOnceAndStoresItsHash(): void
    {
        ['key' => $key, 'plainKey' => $plainKey] = $this->service->create('  Shopify sync ', Role::OPERATOR, null, 'admin@example.com');

        self::assertStringStartsWith(ApiKey::PREFIX, $plainKey);
        self::assertGreaterThanOrEqual(40, \strlen($plainKey));
        self::assertSame('Shopify sync', $key->name());
        self::assertSame(Role::OPERATOR, $key->role());
        self::assertEquals($this->admin->id(), $key->createdBy());
        self::assertEquals($this->clock->now(), $key->createdAt());
        self::assertSame($key, $this->keys->findByHash(ApiKey::hash($plainKey)));
        self::assertNotSame($plainKey, $key->keyHash());
    }

    public function testEveryKeyIsDifferent(): void
    {
        $first = $this->service->create('a', Role::VIEWER)['plainKey'];
        $second = $this->service->create('b', Role::VIEWER)['plainKey'];

        self::assertNotSame($first, $second);
    }

    public function testAKeyCannotBeAnAdmin(): void
    {
        $this->assertViolation('forbidden_role', fn () => $this->service->create('x', Role::ADMIN));
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $this->assertViolation('unknown_role', fn () => $this->service->create('x', 'ROLE_ROOT'));
    }

    public function testANameIsRequired(): void
    {
        $this->assertViolation('required', fn () => $this->service->create('  ', Role::VIEWER));
    }

    public function testTheExpiryMustBeInTheFuture(): void
    {
        $this->assertViolation('in_past', fn () => $this->service->create('x', Role::VIEWER, $this->clock->now()));
    }

    public function testTheCreatorMustExist(): void
    {
        $this->assertViolation('unknown_user', fn () => $this->service->create('x', Role::VIEWER, null, 'nobody@example.com'));
    }

    public function testRevokeKeepsTheFirstRevocationTime(): void
    {
        $key = $this->service->create('x', Role::VIEWER)['key'];

        $this->service->revoke((string) $key->id());
        $first = $key->revokedAt();
        $this->clock->sleep(60);
        $this->service->revoke((string) $key->id());

        self::assertTrue($key->isRevoked());
        self::assertEquals($first, $key->revokedAt());
    }

    public function testExpiryAndLastUseAreDecidedByTheClock(): void
    {
        $key = $this->service->create('x', Role::VIEWER, $this->clock->now()->modify('+1 hour'))['key'];

        self::assertFalse($key->isExpired($this->clock->now()));
        self::assertTrue($key->isExpired($this->clock->now()->modify('+1 hour')));

        self::assertTrue($key->touch($this->clock->now()));
        self::assertFalse($key->touch($this->clock->now()->modify('+30 seconds')), 'touched at most once a minute');
        self::assertTrue($key->touch($this->clock->now()->modify('+61 seconds')));
    }

    private function assertViolation(string $code, callable $create): void
    {
        try {
            $create();
            self::fail('Expected a validation failure.');
        } catch (ValidationFailed $e) {
            self::assertContains($code, array_column($e->violations(), 'code'));
        }
    }
}
