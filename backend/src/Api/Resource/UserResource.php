<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Api\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use Kanso\Core\Internal\Api\State\ListRequest;
use Kanso\Core\Internal\Api\State\UserProcessor;
use Kanso\Core\Internal\Api\State\UserProvider;

/**
 * Someone who signs in to the web UI. Admins manage users; there is always
 * at least one active admin, so demoting or deactivating the last one is a
 * 409. A deactivated user cannot sign in, and their sessions end at once.
 * Everyone changes their own password with POST /api/auth/password.
 */
#[ApiResource(
    shortName: 'User',
    operations: [
        new GetCollection(
            uriTemplate: '/users',
            provider: UserProvider::class,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string'], description: 'Matches any part of the email or name.'),
                'status' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['active', 'deactivated']], description: 'Only active or only deactivated users. Default both.'),
                'sort' => new QueryParameter(schema: ['type' => 'string'], description: 'Comma-separated fields, "-" for descending: email, name, createdAt. Default email.'),
            ],
        ),
        new Get(uriTemplate: '/users/{id}', provider: UserProvider::class),
        new Post(
            uriTemplate: '/users',
            status: 201,
            input: UserInput::class,
            processor: UserProcessor::class,
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            description: 'Add someone with a role and a first password (at least 8 characters), which the admin passes on; no email is sent.',
        ),
        new Patch(
            uriTemplate: '/users/{id}',
            input: UserPatch::class,
            provider: UserProvider::class,
            processor: UserProcessor::class,
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            description: 'Change the email, name or role; what is left out stays. Taking the admin role from the last active admin is a 409.',
        ),
        new Post(
            uriTemplate: '/users/{id}/deactivate',
            name: UserProcessor::DEACTIVATE,
            status: 200,
            input: false,
            read: false,
            deserialize: false,
            processor: UserProcessor::class,
            description: 'Stop the user signing in, and end their sessions. Deactivating yourself or the last active admin is a 409. Deactivating a deactivated user changes nothing.',
        ),
        new Post(
            uriTemplate: '/users/{id}/activate',
            name: UserProcessor::ACTIVATE,
            status: 200,
            input: false,
            read: false,
            deserialize: false,
            processor: UserProcessor::class,
            description: 'Let a deactivated user sign in again, with the password they had.',
        ),
        new Post(
            uriTemplate: '/users/{id}/password',
            name: UserProcessor::SET_PASSWORD,
            status: 200,
            input: UserPasswordInput::class,
            read: false,
            processor: UserProcessor::class,
            denormalizationContext: [ListRequest::ASSIGN_OBJECT => false],
            description: 'Set a new password for someone who forgot theirs (at least 8 characters). Their sessions end.',
        ),
    ],
    security: "is_granted('ROLE_ADMIN')",
    // Every field, every time: a null says "not set", an absent key would say nothing.
    normalizationContext: ['skip_null_values' => false],
)]
final class UserResource
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $email = '';

    public ?string $name = null;

    /** One role, which includes the ones below it: admin, operator, viewer. */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['ROLE_ADMIN', 'ROLE_OPERATOR', 'ROLE_VIEWER']])]
    public string $role = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['active', 'deactivated']])]
    public string $status = 'active';

    public ?\DateTimeImmutable $createdAt = null;
}
