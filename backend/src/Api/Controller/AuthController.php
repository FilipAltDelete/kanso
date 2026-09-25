<?php

declare(strict_types=1);

namespace Kanso\Api\Controller;

use Kanso\Application\Exception\ValidationFailed;
use Kanso\Application\Security\AuthenticationService;
use Kanso\Domain\Security\IssuedTokens;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The access token goes in the body and lives in the browser's memory; the
 * refresh token is an HttpOnly cookie scoped to these endpoints and rotated on
 * every use (same flow as Pimsen).
 */
#[Route('/api/auth')]
final class AuthController
{
    public const string REFRESH_COOKIE = 'kanso_refresh';
    private const string COOKIE_PATH = '/api/auth';

    public function __construct(private readonly AuthenticationService $authentication)
    {
    }

    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = $this->decode($request);
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ('' === $email || '' === $password) {
            throw new ValidationFailed([['path' => '', 'message' => 'Send an email and a password.', 'code' => 'required']]);
        }

        return $this->tokenResponse(
            $this->authentication->login($email, $password, (string) $request->getClientIp()),
            $request,
        );
    }

    #[Route('/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        return $this->tokenResponse(
            $this->authentication->refresh($request->cookies->get(self::REFRESH_COOKIE)),
            $request,
        );
    }

    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->authentication->logout($request->cookies->get(self::REFRESH_COOKIE));

        $response = new JsonResponse(null, 204);
        $response->headers->clearCookie(self::REFRESH_COOKIE, self::COOKIE_PATH, null, $request->isSecure(), true, Cookie::SAMESITE_STRICT);

        return $response;
    }

    #[Route('/me', name: 'auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse([
            'id' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            ...$this->authentication->describe($user->getUserIdentifier()),
        ]);
    }

    private function tokenResponse(IssuedTokens $tokens, Request $request): JsonResponse
    {
        $response = new JsonResponse([
            'accessToken' => $tokens->accessToken,
            'expiresIn' => $tokens->expiresIn,
            'tokenType' => 'Bearer',
        ]);

        $response->headers->setCookie(Cookie::create(
            name: self::REFRESH_COOKIE,
            value: $tokens->refreshToken,
            expire: time() + $tokens->refreshExpiresIn,
            path: self::COOKIE_PATH,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationFailed([['path' => '', 'message' => 'The request body is not valid JSON: '.$e->getMessage(), 'code' => 'malformed_json']]);
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
