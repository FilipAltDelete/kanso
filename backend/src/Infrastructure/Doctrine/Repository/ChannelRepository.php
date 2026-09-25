<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\ChannelStoreInterface;

final class ChannelRepository implements ChannelStoreInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findByCode(string $code): ?Channel
    {
        return $this->em->getRepository(Channel::class)->findOneBy(['code' => $code]);
    }

    public function all(): array
    {
        return array_values($this->em->getRepository(Channel::class)->findBy([], ['name' => 'ASC']));
    }
}
