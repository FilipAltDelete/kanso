<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Security;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A long-lived credential for an integration (as in Pimsen). Only a SHA-256
 * hash of the key is stored: the key itself is shown once, at creation, and
 * cannot be recovered afterwards. A key carries one role and may expire.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_key')]
class ApiKey
{
    /** Every key starts with this, so it can be told apart from a JWT and spotted in a leaked file. */
    public const string PREFIX = 'kso_';

    /** The security identifier of a key's principal is this plus the key id. */
    public const string IDENTIFIER_PREFIX = 'api-key:';

    /** Seconds between writes of `last_used_at`, so authenticating is not a write per request. */
    private const int TOUCH_INTERVAL = 60;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(name: 'key_hash', length: 64, unique: true)]
    private string $keyHash;

    #[ORM\Column(length: 32)]
    private string $role;

    /** The user who created the key; null when it was created from the console. */
    #[ORM\Column(name: 'created_by', type: UuidType::NAME, nullable: true)]
    private ?Uuid $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        string $name,
        string $keyHash,
        string $role,
        ?Uuid $createdBy,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->keyHash = $keyHash;
        $this->role = $role;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    /** The hash a presented key is looked up by. Keys are random, so a fast hash is enough. */
    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function identifier(): string
    {
        return self::IDENTIFIER_PREFIX.$this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function keyHash(): string
    {
        return $this->keyHash;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function createdBy(): ?Uuid
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    /** Records a use; returns whether anything changed and needs saving. */
    public function touch(\DateTimeImmutable $now): bool
    {
        if (null !== $this->lastUsedAt && $now->getTimestamp() - $this->lastUsedAt->getTimestamp() < self::TOUCH_INTERVAL) {
            return false;
        }

        $this->lastUsedAt = $now;

        return true;
    }
}
