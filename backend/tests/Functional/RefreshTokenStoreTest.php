<?php

declare(strict_types=1);

namespace Kanso\Core\Tests\Functional;

use Kanso\Core\Internal\Domain\Security\RefreshTokenStoreInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The Redis store as the API uses it, against the suite's Redis database. */
final class RefreshTokenStoreTest extends KernelTestCase
{
    private RefreshTokenStoreInterface $store;
    private \Redis $redis;
    private string $userId;

    protected function setUp(): void
    {
        $store = static::getContainer()->get(RefreshTokenStoreInterface::class);
        self::assertInstanceOf(RefreshTokenStoreInterface::class, $store);
        $this->store = $store;
        $redis = static::getContainer()->get('Redis');
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->userId = 'user-'.bin2hex(random_bytes(6));
    }

    public function testATokenIsSpentOnceAndRecognisedWhenPresentedAgain(): void
    {
        $token = $this->store->issue($this->userId);

        $first = $this->store->consume($token);
        self::assertNotNull($first);
        self::assertSame($this->userId, $first->userId);
        self::assertFalse($first->replayed);

        $again = $this->store->consume($token);
        self::assertNotNull($again);
        self::assertTrue($again->replayed);
        self::assertSame([$this->userId, $first->family], [$again->userId, $again->family]);

        self::assertNull($this->store->consume('never-issued'));
        self::assertNull($this->store->consume(''));
    }

    public function testARotationStaysInTheFamilyAndASignInStartsAnother(): void
    {
        $token = $this->store->issue($this->userId);
        $consumed = $this->store->consume($token);
        self::assertNotNull($consumed);

        $next = $this->store->rotate($consumed);
        self::assertIsString($next);
        self::assertSame($consumed->family, $this->store->consume($next)?->family);

        $other = $this->store->consume($this->store->issue($this->userId));
        self::assertNotNull($other);
        self::assertNotSame($consumed->family, $other->family);
    }

    public function testRevokingAFamilyEndsItsLiveTokenAndRefusesARotationInFlight(): void
    {
        $first = $this->store->consume($this->store->issue($this->userId));
        self::assertNotNull($first);
        $live = $this->store->rotate($first);
        self::assertIsString($live);
        $inFlight = $this->store->consume($live);
        self::assertNotNull($inFlight);

        $this->store->revokeFamily($first->family);

        self::assertNull($this->store->rotate($inFlight), 'a rotation after the revocation gets nothing');
        $unrelated = $this->store->issue($this->userId);
        self::assertFalse($this->store->consume($unrelated)?->replayed, 'another family is untouched');
    }

    public function testRevokingAFamilyEndsTheTokenItHasNow(): void
    {
        $first = $this->store->consume($this->store->issue($this->userId));
        self::assertNotNull($first);
        $live = $this->store->rotate($first);
        self::assertIsString($live);

        $this->store->revokeFamily($first->family);

        self::assertNull($this->store->consume($live));
    }

    public function testSigningOutWithASpentTokenStillEndsTheSession(): void
    {
        $spent = $this->store->issue($this->userId);
        $consumed = $this->store->consume($spent);
        self::assertNotNull($consumed);
        $live = $this->store->rotate($consumed);
        self::assertIsString($live);

        $this->store->revoke($spent);

        self::assertNull($this->store->consume($live));
    }

    public function testEverySessionOfTheUserEndsAndNoOneElses(): void
    {
        $mine = [$this->store->issue($this->userId), $this->store->issue($this->userId)];
        $theirs = $this->store->issue('someone-else-'.bin2hex(random_bytes(6)));

        $this->store->revokeAllFor($this->userId);

        self::assertSame([null, null], array_map(fn (string $token) => $this->store->consume($token), $mine));
        self::assertFalse($this->store->consume($theirs)?->replayed);
        self::assertSame([], $this->redis->sMembers('refresh_families:'.$this->userId));
    }

    public function testASignInForgetsFamiliesThatHaveEnded(): void
    {
        $this->store->issue($this->userId);
        $this->store->revokeAllFor($this->userId);
        $ended = $this->store->consume($this->store->issue($this->userId));
        self::assertNotNull($ended);
        $this->store->revokeFamily($ended->family);

        $this->store->issue($this->userId);

        $families = $this->redis->sMembers('refresh_families:'.$this->userId);
        self::assertIsArray($families);
        self::assertCount(1, $families);
        self::assertNotContains($ended->family, $families);
    }

    public function testOnlyHashesAreStoredAndASpentTokenIsRememberedForTheRestOfItsLife(): void
    {
        $token = $this->store->issue($this->userId);
        $fingerprint = hash('sha256', $token);
        self::assertEqualsWithDelta($this->store->ttl(), $this->redis->ttl('refresh_token:'.$fingerprint), 5);

        $consumed = $this->store->consume($token);
        self::assertNotNull($consumed);

        self::assertSame(0, $this->redis->exists('refresh_token:'.$fingerprint));
        self::assertEqualsWithDelta($this->store->ttl(), $this->redis->ttl('refresh_spent:'.$fingerprint), 5);
        $stored = [
            'refresh_spent:'.$fingerprint => $this->redis->get('refresh_spent:'.$fingerprint),
            'refresh_family:'.$consumed->family => $this->redis->get('refresh_family:'.$consumed->family),
            'refresh_families:'.$this->userId => implode(' ', (array) $this->redis->sMembers('refresh_families:'.$this->userId)),
        ];
        self::assertSame($this->userId.'|'.$consumed->family, $stored['refresh_spent:'.$fingerprint]);
        self::assertStringNotContainsString($token, json_encode($stored, \JSON_THROW_ON_ERROR));
    }
}
