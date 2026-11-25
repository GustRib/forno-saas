<?php

declare(strict_types=1);

namespace App\Tenant\Application;

use App\Identity\Application\RegisterUser;
use App\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\DomainException;
use App\Tenant\Domain\Model\TenantUser;
use App\Tenant\Domain\Role;
use App\Tenant\Infrastructure\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * FR-005: administrador adiciona e desativa usuários do estabelecimento.
 *
 * No MVP o administrador define uma senha inicial; o convite por e-mail com
 * definição de senha pelo próprio usuário é a história FNO-014 (P1).
 */
final class ManageMembers
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $context,
        private readonly TenantRepository $tenants,
        private readonly UserRepository $users,
        private readonly RegisterUser $registerUser,
    ) {
    }

    public function add(string $name, string $email, Role $role, ?string $initialPassword): TenantUser
    {
        $tenantId = $this->context->getTenantId();
        $this->assertCanAssign($role);

        return $this->em->wrapInTransaction(function () use ($tenantId, $name, $email, $role, $initialPassword): TenantUser {
            $user = $this->users->findByEmail($email);
            if (null === $user) {
                if (null === $initialPassword || '' === $initialPassword) {
                    throw DomainException::invalidField('password', 'Informe uma senha inicial para o novo usuário.');
                }
                $user = ($this->registerUser)($name, $email, $initialPassword);
            } elseif (null !== $this->tenants->findMemberByUser($tenantId, $user->getId())) {
                throw DomainException::conflict('Este usuário já faz parte do estabelecimento.', 'ALREADY_MEMBER');
            }

            $membership = new TenantUser($tenantId, $user->getId(), $role);
            $this->tenants->addMembership($membership);
            $this->em->flush();

            return $membership;
        });
    }

    public function update(Uuid $membershipId, Role $role, string $status): TenantUser
    {
        $tenantId = $this->context->getTenantId();
        $membership = $this->tenants->findMember($tenantId, $membershipId)
            ?? throw DomainException::notFound('Usuário não encontrado neste estabelecimento.');

        if ($membership->getUserId()->equals($this->context->getUser()->getId())) {
            throw new DomainException('Você não pode alterar o próprio papel ou situação.', 'CANNOT_CHANGE_SELF');
        }

        if ($role !== $membership->getRole()) {
            $this->assertCanAssign($role);
            if (Role::OWNER === $membership->getRole()) {
                $this->assertCanAssign(Role::OWNER);
            }
        }

        $wasActiveOwner = Role::OWNER === $membership->getRole() && $membership->isActive();
        $membership->changeRole($role);
        $membership->changeStatus($status);

        $stillActiveOwner = Role::OWNER === $membership->getRole() && $membership->isActive();
        if ($wasActiveOwner && !$stillActiveOwner && $this->tenants->countActiveOwners($tenantId) <= 1) {
            throw new DomainException('O estabelecimento precisa de ao menos um proprietário ativo.', 'LAST_OWNER');
        }

        $this->em->flush();

        return $membership;
    }

    private function assertCanAssign(Role $role): void
    {
        if (Role::OWNER === $role && Role::OWNER !== $this->context->getMembership()->getRole()) {
            throw new DomainException('Somente um proprietário pode conceder o papel de proprietário.', 'FORBIDDEN', 403);
        }
    }
}
