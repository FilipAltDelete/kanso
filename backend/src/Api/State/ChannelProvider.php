<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Kanso\Core\Internal\Api\Resource\ChannelResource;
use Kanso\Core\Internal\Domain\Order\Channel;
use Kanso\Core\Internal\Domain\Order\ChannelStoreInterface;

/**
 * @implements ProviderInterface<ChannelResource>
 */
final class ChannelProvider implements ProviderInterface
{
    public function __construct(private readonly ChannelStoreInterface $channels)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(self::present(...), $this->channels->all());
        }

        $channel = $this->channels->findByCode((string) ($uriVariables['code'] ?? ''));

        return null === $channel ? null : self::present($channel);
    }

    private static function present(Channel $channel): ChannelResource
    {
        $resource = new ChannelResource();
        $resource->code = $channel->code();
        $resource->name = $channel->name();
        $resource->type = $channel->type();
        $resource->currency = $channel->currency();

        return $resource;
    }
}
