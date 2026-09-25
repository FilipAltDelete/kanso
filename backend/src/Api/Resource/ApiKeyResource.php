<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\ApiKeyProcessor;
use Kanso\Core\Internal\Api\State\ApiKeyProvider;
use Kanso\Core\Internal\Api\State\ListRequest;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A credential for an integration. Only a hash of the key is stored: the key
 * itself is in the one response that created it, and never again. Revoked
 * and expired keys stay listed, so what an integration used can be traced.
 * Managing keys takes the admin role, which no key can have.
 */
#[ApiResource(
    shortName: 'ApiKey',
    operations: [
        new GetCollection(
            uriTemplate: '/api-keys',
            provider: ApiKeyProvider::class,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Matches any part of the name.'),
                'sort' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated fields, "-" for descending: name, role, createdAt, expiresAt, lastUsedAt. Default -createdAt.'),
            ],
        ),
        new Get(uriTemplate: '/api-keys/{id}', provider: ApiKeyProvider::class),
        new Post(
            uriTemplate: '/api-keys',
            status: 201,
            input: ApiKeyInput::class,
            processor: ApiKeyProcessor::class,
            normalizationContext: ['groups' => ['api_key:read', 'api_key:created'], 'skip_null_values' => false],
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            description: 'Create a key with one role (operator or viewer; never admin) and an optional expiry. The answer carries the key in `key`: show it to whoever sets up the integration now, because it cannot be read again.',
        ),
        new Post(
            uriTemplate: '/api-keys/{id}/revoke',
            status: 200,
            input: false,
            read: false,
            deserialize: false,
            processor: ApiKeyProcessor::class,
            description: 'Stop the key working, from the next request. It stays listed as revoked. Revoking a revoked key changes nothing. Cannot be undone: create a new key instead.',
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['groups' => ['api_key:read'], 'skip_null_values' => false],
)]
final class ApiKeyResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['api_key:read'])]
    public string $id = '';

    #[Groups(['api_key:read'])]
    public string $name = '';

    /** ROLE_OPERATOR or ROLE_VIEWER. */
    #[Groups(['api_key:read'])]
    public string $role = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['active', 'expired', 'revoked']])]
    #[Groups(['api_key:read'])]
    public string $status = 'active';

    /** Who created it; null for a key created from the console. */
    #[Groups(['api_key:read'])]
    public ?string $createdBy = null;

    #[Groups(['api_key:read'])]
    public ?string $createdByName = null;

    #[Groups(['api_key:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    #[Groups(['api_key:read'])]
    public ?\DateTimeImmutable $expiresAt = null;

    /** To the minute: uses within a minute of the last are not recorded. */
    #[Groups(['api_key:read'])]
    public ?\DateTimeImmutable $lastUsedAt = null;

    #[Groups(['api_key:read'])]
    public ?\DateTimeImmutable $revokedAt = null;

    /** The key itself, only in the response that created it. */
    #[ApiProperty(description: 'The key itself; only in the response that created it.')]
    #[Groups(['api_key:created'])]
    public ?string $key = null;
}
