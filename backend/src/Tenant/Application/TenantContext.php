<?php

declare(strict_types=1);

namespace App\Tenant\Application;

use App\Identity\Domain\Model\User;
use App\Shared\Domain\DomainException;
use App\Tenant\Domain\Model\Tenant;
use App\Tenant\Domain\Model\TenantUser;
use App\Tenant\Infrastructure\TenantRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tenant ativo da requisição (ARCHITECTURE §6).
 *
 * O tenant é sempre resolvido no backend a partir do usuário autenticado e
 * de uma associação ativa em tenant_users. O ID escolhido fica na sessão do
 * servidor; o cliente nunca informa tenant_id diretamente nos cadastros.
 */
final class TenantContext implements ResetInterface
{
    private const SESSION_KEY = '_forno_active_tenant';

    private bool $resolved = false;
    private ?TenantUser $membership = null;
    private ?Tenant $tenant = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly TenantRepository $tenants,
    ) {
    }

    public function hasTenant(): bool
    {
        $this->resolve();

        return null !== $this->membership;
    }

    public function getTenantId(): Uuid
    {
        return $this->getMembership()->getTenantId();
    }

    public function getTenant(): Tenant
    {
        $this->getMembership();
        \assert(null !== $this->tenant);

        return $this->tenant;
    }

    public function getMembership(): TenantUser
    {
        $this->resolve();
        if (null === $this->membership) {
            throw new DomainException('Nenhum estabelecimento ativo para este usuário.', 'TENANT_REQUIRED', 403);
        }

        return $this->membership;
    }

    public function getUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new DomainException('Faça login para continuar.', 'UNAUTHENTICATED', 401);
        }

        return $user;
    }

    public function hasPermission(string $permission): bool
    {
        $this->resolve();

        return null !== $this->membership && $this->membership->hasPermission($permission);
    }

    /**
     * Para logs: não dispara consultas, só informa o que já foi resolvido.
     */
    public function peekTenantId(): ?string
    {
        return $this->membership?->getTenantId()->toRfc4122();
    }

    /**
     * Troca o estabelecimento ativo, validando a associação.
     */
    public function select(Uuid $tenantId): void
    {
        $membership = $this->tenants->findActiveMembership($this->getUser()->getId(), $tenantId);
        if (null === $membership) {
            // 404 para não revelar a existência de tenants alheios.
            throw DomainException::notFound('Estabelecimento não encontrado.');
        }

        $this->requestStack->getSession()->set(self::SESSION_KEY, $tenantId->toRfc4122());
        $this->reset();
    }

    public function clearSelection(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request && $request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->membership = null;
        $this->tenant = null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // não marca como resolvido: o login pode acontecer depois
        }
        $this->resolved = true;

        $request = $this->requestStack->getCurrentRequest();
        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;

        $selected = $session?->get(self::SESSION_KEY);
        $membership = null;
        if (\is_string($selected) && Uuid::isValid($selected)) {
            $membership = $this->tenants->findActiveMembership($user->getId(), Uuid::fromString($selected));
        }

        if (null === $membership) {
            $first = $this->tenants->listActiveMembershipsForUser($user->getId())[0] ?? null;
            $membership = $first['membership'] ?? null;
            if (null !== $membership) {
                $session?->set(self::SESSION_KEY, $membership->getTenantId()->toRfc4122());
            } else {
                $session?->remove(self::SESSION_KEY);
            }
        }

        $this->membership = $membership;
        $this->tenant = null !== $membership ? $this->tenants->find($membership->getTenantId()) : null;
    }
}
