<?php

declare(strict_types=1);

namespace Kanso\Domain\User;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A person who signs in to the web UI. A plain entity: the security layer's
 * view of it (`AuthenticatedUser`) lives in Infrastructure.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $name;

    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    private ?string $passwordHash = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $roles */
    public function __construct(string $email, array $roles, ?string $name, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->roles = $roles;
        $this->name = $name;
        $this->createdAt = $createdAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
