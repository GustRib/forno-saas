<?php

declare(strict_types=1);

namespace App\Tenant\Presentation;

use App\Identity\Domain\Model\User;
use App\Identity\Presentation\SessionPayload;
use App\Shared\Presentation\ApiResponse;
use App\Tenant\Application\TenantContext;
use App\Tenant\Presentation\Request\SelectTenantRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final class SessionTenantController
{
    #[Route('/api/v1/session/tenant', name: 'api_session_tenant', methods: ['POST'])]
    public function __invoke(
        #[MapRequestPayload] SelectTenantRequest $payload,
        #[CurrentUser] User $user,
        TenantContext $tenantContext,
        SessionPayload $sessionPayload,
    ): JsonResponse {
        $tenantContext->select(Uuid::fromString($payload->tenantId));

        return ApiResponse::data($sessionPayload->build($user));
    }
}
