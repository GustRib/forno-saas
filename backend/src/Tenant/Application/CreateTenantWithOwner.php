<?php

declare(strict_types=1);

namespace App\Tenant\Application;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Model\User;
use App\Tenant\Domain\Event\TenantCreated;
use App\Tenant\Domain\Model\Tenant;
use App\Tenant\Domain\Model\TenantUser;
use App\Tenant\Domain\Role;
use App\Tenant\Infrastructure\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * FNO-011: o primeiro usuário cria o estabelecimento e recebe papel OWNER.
 * Tudo acontece numa única transação.
 */
final class CreateTenantWithOwner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegisterUser $registerUser,
        private readonly TenantRepository $tenants,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    public function __invoke(string $tenantName, string $ownerName, string $email, string $plainPassword): array
    {
        return $this->em->wrapInTransaction(function () use ($tenantName, $ownerName, $email, $plainPassword): array {
            $user = ($this->registerUser)($ownerName, $email, $plainPassword);

            $tenant = new Tenant($tenantName);
            $this->tenants->add($tenant);
            $this->tenants->addMembership(new TenantUser($tenant->getId(), $user->getId(), Role::OWNER));
            $this->em->flush();

            $this->dispatcher->dispatch(new TenantCreated($tenant->getId(), $user->getId()));
            $this->em->flush();

            return ['tenant' => $tenant, 'user' => $user];
        });
    }
}
