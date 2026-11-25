<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class UserRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(Uuid $id): ?User
    {
        return $this->em->find(User::class, $id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, User> indexado pelo UUID em string
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $users = $this->em->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id) => $id->toRfc4122(), $ids))
            ->getQuery()->getResult();

        $indexed = [];
        foreach ($users as $user) {
            $indexed[$user->getId()->toRfc4122()] = $user;
        }

        return $indexed;
    }

    public function add(User $user): void
    {
        $this->em->persist($user);
    }
}
