<?php

declare(strict_types=1);

namespace Kanso\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Domain\User\User;
use Kanso\Domain\User\UserStoreInterface;
use Symfony\Component\Uid\Uuid;

final class UserRepository implements UserStoreInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findById(string $id): ?User
    {
        return Uuid::isValid($id) ? $this->em->find(User::class, Uuid::fromString($id)) : null;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }

    public function count(): int
    {
        return $this->em->getRepository(User::class)->count([]);
    }
}
