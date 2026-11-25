<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Security;

use App\Shared\Presentation\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

final class JsonLoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return ApiResponse::error(
                'TOO_MANY_LOGIN_ATTEMPTS',
                'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.',
                429,
            );
        }

        // Sempre a mesma mensagem: não revela se o e-mail existe.
        return ApiResponse::error('INVALID_CREDENTIALS', 'E-mail ou senha inválidos.', 401);
    }
}
