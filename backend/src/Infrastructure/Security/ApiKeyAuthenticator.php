<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Infrastructure\Security;

use Kanso\Core\Internal\Domain\Security\ApiKey;
use Kanso\Core\Internal\Domain\Security\ApiKeyStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * An API key arrives as `X-Api-Key: kso_…` or `Authorization: Bearer kso_…`
 * and resolves to a principal with the key's role. Any other bearer token is
 * left to the JWT authenticator (see JwtTokenExtractor).
 *
 * Each key has its own rate-limit window, so one runaway integration cannot
 * spend the allowance of another.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const string HEADER = 'X-Api-Key';

    public function __construct(
        private readonly ApiKeyStoreInterface $keys,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $apiKeyLimiter,
    ) {
    }

    public static function extract(Request $request): ?string
    {
        $header = trim((string) $request->headers->get(self::HEADER, ''));
        if ('' !== $header) {
            return $header;
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($authorization, 'Bearer '.ApiKey::PREFIX)) {
            return trim(substr($authorization, \strlen('Bearer ')));
        }

        return null;
    }

    public function supports(Request $request): bool
    {
        return null !== self::extract($request);
    }

    public function authenticate(Request $request): Passport
    {
        $presented = self::extract($request) ?? throw new CustomUserMessageAuthenticationException('No API key.');
        $key = $this->keys->findByHash(ApiKey::hash($presented));
        $now = $this->clock->now();

        if (null === $key || $key->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('This API key is not valid.');
        }

        if ($key->isExpired($now)) {
            throw new CustomUserMessageAuthenticationException('This API key has expired.');
        }

        $limit = $this->apiKeyLimiter->create((string) $key->id())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiKeyRateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - $now->getTimestamp()), $limit->getLimit());
        }

        if ($key->touch($now)) {
            $this->keys->save($key);
        }

        return new SelfValidatingPassport(new UserBadge(
            $key->identifier(),
            static fn (): AuthenticatedUser => AuthenticatedUser::fromApiKey($key),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof ApiKeyRateLimited) {
            return self::problem(429, 'Too Many Requests', \sprintf(
                'This API key has used its allowance of %d requests a minute. Try again in %d seconds.',
                $exception->limit,
                $exception->retryAfter,
            ), ['Retry-After' => (string) $exception->retryAfter]);
        }

        return self::problem(401, 'Unauthorized', $exception->getMessageKey());
    }

    /**
     * RFC 7807, built here rather than with Api\Http\ProblemResponse because
     * Infrastructure may not depend on the API layer.
     *
     * @param array<string, string> $headers
     */
    private static function problem(int $status, string $title, string $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        );
    }
}
