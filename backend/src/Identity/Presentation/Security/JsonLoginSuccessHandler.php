<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Security;

use App\Identity\Domain\Model\User;
use App\Identity\Presentation\SessionPayload;
use App\Shared\Presentation\ApiResponse;
use App\Tenant\Application\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class JsonLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SessionPayload $sessionPayload,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        \assert($user instanceof User);

        // Novo login: escolhe novamente o estabelecimento ativo.
        $this->tenantContext->clearSelection();
        $this->tenantContext->reset();

        return ApiResponse::data($this->sessionPayload->build($user));
    }
}
