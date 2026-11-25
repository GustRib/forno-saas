<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Domain\Model\User;
use App\Identity\Presentation\Request\RegisterRequest;
use App\Shared\Domain\DomainException;
use App\Shared\Presentation\ApiResponse;
use App\Tenant\Application\CreateTenantWithOwner;
use App\Tenant\Application\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/auth')]
final class AuthController
{
    public function __construct(
        private readonly SessionPayload $sessionPayload,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Interceptado pelo json_login do firewall; o corpo só executa se a
     * requisição não tiver o formato esperado.
     */
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return ApiResponse::error('BAD_REQUEST', 'Informe e-mail e senha.', 400);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Interceptado pelo firewall.');
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $payload,
        Request $request,
        CreateTenantWithOwner $createTenant,
        Security $security,
        #[Autowire(service: 'limiter.registration')] RateLimiterFactoryInterface $registrationLimiter,
        bool $registrationEnabled,
    ): JsonResponse {
        if (!$registrationEnabled) {
            throw new DomainException('O cadastro de novos estabelecimentos está desativado.', 'REGISTRATION_DISABLED', 403);
        }

        if (!$registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new DomainException('Muitas tentativas de cadastro. Tente novamente mais tarde.', 'TOO_MANY_REQUESTS', 429);
        }

        $result = $createTenant($payload->bakeryName, $payload->name, $payload->email, $payload->password);

        $security->login($result['user'], 'json_login', 'api');
        $this->tenantContext->clearSelection();
        $this->tenantContext->reset();

        return ApiResponse::data($this->sessionPayload->build($result['user']), 201);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::data($this->sessionPayload->build($user));
    }
}
