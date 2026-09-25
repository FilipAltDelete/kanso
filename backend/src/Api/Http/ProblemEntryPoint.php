<?php

declare(strict_types=1);

namespace Kanso\Api\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ProblemEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ProblemResponse::create(401, 'Unauthorized', 'This endpoint needs an access token. Sign in at POST /api/auth/login.');
    }
}
