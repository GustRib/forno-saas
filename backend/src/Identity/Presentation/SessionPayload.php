<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Domain\Model\User;
use App\Tenant\Application\TenantContext;
use App\Tenant\Infrastructure\TenantRepository;

/**
 * Dados da sessão entregues ao frontend: usuário, tenant ativo, papel,
 * permissões efetivas e demais estabelecimentos disponíveis.
 */
final class SessionPayload
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantRepository $tenants,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $active = null;
        if ($this->tenantContext->hasTenant()) {
            $tenant = $this->tenantContext->getTenant();
            $membership = $this->tenantContext->getMembership();
            $active = [
                'id' => $tenant->getId()->toRfc4122(),
                'name' => $tenant->getName(),
                'timezone' => $tenant->getTimezone(),
                'currency' => $tenant->getCurrency(),
                'role' => $membership->getRole()->value,
                'roleLabel' => $membership->getRole()->label(),
                'permissions' => $membership->effectivePermissions(),
            ];
        }

        $available = array_map(static fn (array $row) => [
            'id' => $row['tenant']->getId()->toRfc4122(),
            'name' => $row['tenant']->getName(),
            'role' => $row['membership']->getRole()->value,
        ], $this->tenants->listActiveMembershipsForUser($user->getId()));

        return [
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
            ],
            'activeTenant' => $active,
            'tenants' => $available,
            // FR-100: o MVP não emite documentos fiscais.
            'fiscal' => [
                'available' => false,
                'notice' => 'Emissão fiscal não disponível nesta versão. Continue usando seu emissor fiscal atual.',
            ],
        ];
    }
}
