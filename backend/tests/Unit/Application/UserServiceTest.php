<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Unit\Application;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\NotFound;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Application\Security\SecurityLog;
use Kanso\Core\Internal\Application\User\UserService;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Security\PasswordHasherInterface;
use Kanso\Core\Internal\Domain\User\Role;
use Kanso\Core\Internal\Domain\User\User;
use Kanso\Core\Tests\Support\InMemoryRefreshTokens;
use Kanso\Core\Tests\Support\InMemoryUsers;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UserServiceTest extends TestCase
{
    private InMemoryUsers $users;
    private InMemoryRefreshTokens $refreshTokens;
    private TestHandler $log;
    private UserService $service;
    private User $admin;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->refreshTokens = new InMemoryRefreshTokens();
        $hasher = new class implements PasswordHasherInterface {
            public function hash(string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }

            public function verify(string $hash, string $plainPassword): bool
            {
                return $hash === 'hashed:'.$plainPassword;
            }

            public function decoyHash(): string
            {
                return 'hashed:decoy';
            }
        };
        $transaction = new class implements TransactionInterface {
            public function run(callable $work): mixed
            {
                return $work();
            }

            public function forget(): void
            {
            }
        };

        $this->log = new TestHandler();
        $this->service = new UserService($this->users, $hasher, new MockClock('2026-09-25 12:00:00'), $transaction, $this->refreshTokens, new SecurityLog(new Logger('kanso_security', [$this->log])));
        $this->admin = $this->service->create('admin@example.com', 'admin', [Role::ADMIN]);
    }

    public function testAnAdminAddsSomeoneWithOneRoleAndAFirstPassword(): void
    {
        $user = $this->service->createFromRequest(['email' => ' pia@example.com ', 'name' => ' Pia ', 'role' => Role::OPERATOR, 'password' => 'first one']);

        self::assertSame('pia@example.com', $user->email());
        self::assertSame('Pia', $user->name());
        self::assertSame([Role::OPERATOR], $user->roles());
        self::assertSame('hashed:first one', $user->passwordHash());
        self::assertTrue($user->isEnabled());
        self::assertSame($user, $this->users->findByEmail('PIA@example.com'));
    }

    public function testEveryProblemWithANewUserIsListed(): void
    {
        $this->assertViolations(
            [['email', 'invalid_email'], ['role', 'unknown_role'], ['password', 'too_short']],
            fn () => $this->service->createFromRequest(['email' => 'not an email', 'role' => 'ROLE_ROOT', 'password' => 'short']),
        );
        $this->assertViolations(
            [['email', 'required'], ['name', 'type'], ['role', 'required'], ['password', 'required']],
            fn () => $this->service->createFromRequest(['name' => 42]),
        );
        $this->assertViolations(
            [['email', 'duplicate']],
            fn () => $this->service->createFromRequest(['email' => 'ADMIN@example.com', 'role' => Role::VIEWER, 'password' => 'long enough']),
        );
    }

    public function testAnUpdateChangesOnlyWhatWasSent(): void
    {
        $user = $this->service->createFromRequest(['email' => 'pia@example.com', 'name' => 'Pia', 'role' => Role::OPERATOR, 'password' => 'first one']);

        $this->service->update((string) $user->id(), ['role' => Role::VIEWER]);
        self::assertSame(['pia@example.com', 'Pia', Role::VIEWER], [$user->email(), $user->name(), $user->role()]);

        $this->service->update((string) $user->id(), ['email' => 'pia.s@example.com', 'name' => null]);
        self::assertSame(['pia.s@example.com', null, Role::VIEWER], [$user->email(), $user->name(), $user->role()]);

        // Keeping one's own email, in another case, is no duplicate.
        $this->service->update((string) $user->id(), ['email' => 'PIA.S@example.com']);
        self::assertSame('PIA.S@example.com', $user->email());

        $this->assertViolations([['email', 'duplicate']], fn () => $this->service->update((string) $user->id(), ['email' => 'admin@example.com']));
    }

    public function testTheLastActiveAdminKeepsTheAdminRole(): void
    {
        $this->assertConflict('last_admin', fn () => $this->service->update((string) $this->admin->id(), ['role' => Role::OPERATOR]));
        self::assertSame(Role::ADMIN, $this->admin->role());
        self::assertSame(1, $this->users->adminLocks, 'checked with the admins locked');

        // Renaming the last admin is fine.
        $this->service->update((string) $this->admin->id(), ['name' => 'Boss']);

        $other = $this->service->createFromRequest(['email' => 'second@example.com', 'role' => Role::ADMIN, 'password' => 'long enough']);
        $this->service->update((string) $this->admin->id(), ['role' => Role::OPERATOR]);
        self::assertSame(Role::OPERATOR, $this->admin->role());

        $this->assertConflict('last_admin', fn () => $this->service->update((string) $other->id(), ['role' => Role::VIEWER]));
    }

    public function testAnotherAdminWhoIsDeactivatedDoesNotCount(): void
    {
        $other = $this->service->createFromRequest(['email' => 'second@example.com', 'role' => Role::ADMIN, 'password' => 'long enough']);
        $this->service->deactivate((string) $other->id(), (string) $this->admin->id());

        $this->assertConflict('last_admin', fn () => $this->service->update((string) $this->admin->id(), ['role' => Role::VIEWER]));
        $this->assertConflict('last_admin', fn () => $this->service->deactivate((string) $this->admin->id(), (string) $other->id()));

        // A deactivated admin can be demoted: they are not the one left.
        $this->service->update((string) $other->id(), ['role' => Role::VIEWER]);
        self::assertSame(Role::VIEWER, $other->role());
    }

    public function testDeactivatingEndsTheSessionsAndActivatingLetsThemBackIn(): void
    {
        $user = $this->service->createFromRequest(['email' => 'pia@example.com', 'role' => Role::OPERATOR, 'password' => 'first one']);
        $this->refreshTokens->issue((string) $user->id());
        $mine = $this->refreshTokens->issue((string) $this->admin->id());

        $this->service->deactivate((string) $user->id(), (string) $this->admin->id());
        self::assertFalse($user->isEnabled());
        self::assertSame([$mine], array_keys($this->refreshTokens->tokens));

        $this->service->deactivate((string) $user->id(), (string) $this->admin->id());
        self::assertFalse($user->isEnabled(), 'deactivating twice changes nothing');

        $this->service->activate((string) $user->id());
        self::assertTrue($user->isEnabled());
        self::assertSame('hashed:first one', $user->passwordHash());
    }

    public function testNobodyDeactivatesThemselves(): void
    {
        $this->service->createFromRequest(['email' => 'second@example.com', 'role' => Role::ADMIN, 'password' => 'long enough']);

        $this->assertConflict('self', fn () => $this->service->deactivate((string) $this->admin->id(), (string) $this->admin->id()));
        self::assertTrue($this->admin->isEnabled());
    }

    public function testAnAdminSetsAForgottenPassword(): void
    {
        $user = $this->service->createFromRequest(['email' => 'pia@example.com', 'role' => Role::OPERATOR, 'password' => 'first one']);
        $this->refreshTokens->issue((string) $user->id());

        $this->assertViolations([['password', 'too_short']], fn () => $this->service->setPassword((string) $user->id(), 'short'));
        $this->service->setPassword((string) $user->id(), 'second one');

        self::assertSame('hashed:second one', $user->passwordHash());
        self::assertSame([], $this->refreshTokens->tokens);
    }

    public function testChangesToWhoMayDoWhatAreLoggedWithWhoMadeThem(): void
    {
        $by = (string) $this->admin->id();
        $user = $this->service->createFromRequest(['email' => 'pia@example.com', 'name' => 'Pia', 'role' => Role::OPERATOR, 'password' => 'first one'], $by);
        $id = (string) $user->id();

        $this->service->update($id, ['name' => 'Pia S']);
        $this->service->update($id, ['role' => Role::VIEWER], $by);
        $this->service->setPassword($id, 'second one', $by);
        $this->service->deactivate($id, $by);
        $this->service->deactivate($id, $by);
        $this->service->activate($id, $by);
        $this->service->activate($id, $by);

        self::assertSame([
            ['user_created', $id, $by, Role::OPERATOR, null, null],
            ['user_role_changed', $id, $by, null, Role::OPERATOR, Role::VIEWER],
            ['password_changed', $id, $by, null, null, null],
            ['user_deactivated', $id, $by, null, null, null],
            ['user_activated', $id, $by, null, null, null],
        ], array_map(
            static fn (LogRecord $r): array => [$r->context['event'], $r->context['user_id'], $r->context['actor_id'], $r->context['role'] ?? null, $r->context['from'] ?? null, $r->context['to'] ?? null],
            \array_slice($this->log->getRecords(), 1),
        ), 'a rename, and a repeated (de)activation, change nothing that matters');
        self::assertSame(['user_created', null], [$this->log->getRecords()[0]->context['event'], $this->log->getRecords()[0]->context['actor_id']], 'the admin from setUp, made at the console');
        $logged = json_encode(array_map(static fn (LogRecord $r): array => $r->toArray(), $this->log->getRecords()), \JSON_THROW_ON_ERROR);
        foreach (['first one', 'second one', 'pia@example.com'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
        }
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->service->activate('0192f000-0000-7000-8000-000000000000');
    }

    public function testTheRoleIsTheHighestOfSeveral(): void
    {
        self::assertSame(Role::OPERATOR, $this->service->create('old@example.com', 'x', [Role::VIEWER, Role::OPERATOR])->role());
        self::assertSame(Role::VIEWER, Role::highest([]));
    }

    /** @param list<array{string, string}> $expected path and code of each violation */
    private function assertViolations(array $expected, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a validation failure.');
        } catch (ValidationFailed $e) {
            self::assertSame($expected, array_map(static fn (array $v): array => [$v['path'], $v['code']], $e->violations()));
        }
    }

    private function assertConflict(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a conflict.');
        } catch (Conflict $e) {
            self::assertSame([$code], array_column($e->violations(), 'code'));
        }
    }
}
