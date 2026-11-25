<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Security;

use App\Shared\Presentation\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiResponse::error('UNAUTHENTICATED', 'Faça login para continuar.', 401);
    }
}
