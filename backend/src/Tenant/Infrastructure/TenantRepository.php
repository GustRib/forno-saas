<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Tenant\Domain\Model\Tenant;
use App\Tenant\Domain\Model\TenantUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TenantRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(Uuid $id): ?Tenant
    {
        return $this->em->find(Tenant::class, $id);
    }

    public function add(Tenant $tenant): void
    {
        $this->em->persist($tenant);
    }

    public function addMembership(TenantUser $membership): void
    {
        $this->em->persist($membership);
    }

    /**
     * Associação ativa do usuário em um estabelecimento ativo.
     */
    public function findActiveMembership(Uuid $userId, Uuid $tenantId): ?TenantUser
    {
        return $this->em->createQueryBuilder()
            ->select('tu')
            ->from(TenantUser::class, 'tu')
            ->join(Tenant::class, 't', 'WITH', 't.id = tu.tenantId')
            ->where('tu.userId = :user')
            ->andWhere('tu.tenantId = :tenant')
            ->andWhere('tu.status = :active')
            ->andWhere('t.status = :active')
            ->setParameter('user', $userId->toRfc4122())
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('active', 'ACTIVE')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Estabelecimentos ativos aos quais o usuário tem acesso, mais antigos primeiro.
     *
     * @return list<array{membership: TenantUser, tenant: Tenant}>
     */
    public function listActiveMembershipsForUser(Uuid $userId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('tu', 't')
            ->from(TenantUser::class, 'tu')
            ->join(Tenant::class, 't', 'WITH', 't.id = tu.tenantId')
            ->where('tu.userId = :user')
            ->andWhere('tu.status = :active')
            ->andWhere('t.status = :active')
            ->orderBy('tu.createdAt', 'ASC')
            ->setParameter('user', $userId->toRfc4122())
            ->setParameter('active', 'ACTIVE')
            ->getQuery()
            ->getResult();

        // Resultado misto: [TenantUser, Tenant, TenantUser, Tenant, ...]
        $result = [];
        for ($i = 0, $n = \count($rows); $i < $n; $i += 2) {
            $result[] = ['membership' => $rows[$i], 'tenant' => $rows[$i + 1]];
        }

        return $result;
    }

    /**
     * @return list<TenantUser>
     */
    public function listMembers(Uuid $tenantId): array
    {
        return $this->em->getRepository(TenantUser::class)->findBy(
            ['tenantId' => $tenantId->toRfc4122()],
            ['createdAt' => 'ASC'],
        );
    }

    public function findMember(Uuid $tenantId, Uuid $membershipId): ?TenantUser
    {
        return $this->em->getRepository(TenantUser::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'id' => $membershipId->toRfc4122(),
        ]);
    }

    public function findMemberByUser(Uuid $tenantId, Uuid $userId): ?TenantUser
    {
        return $this->em->getRepository(TenantUser::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'userId' => $userId->toRfc4122(),
        ]);
    }

    public function countActiveOwners(Uuid $tenantId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(tu.id)')
            ->from(TenantUser::class, 'tu')
            ->where('tu.tenantId = :tenant')
            ->andWhere('tu.role = :owner')
            ->andWhere('tu.status = :active')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('owner', 'OWNER')
            ->setParameter('active', 'ACTIVE')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
