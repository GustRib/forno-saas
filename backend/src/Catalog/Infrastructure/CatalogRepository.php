<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\Ingredient;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

/**
 * Persistência do módulo Catalog. TODA consulta recebe o tenant de forma
 * explícita (FR-003); não há método que busque só por ID.
 */
final class CatalogRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function add(object $entity): void
    {
        $this->em->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->em->remove($entity);
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function findForTenant(string $class, Uuid $tenantId, Uuid $id): ?object
    {
        return $this->em->getRepository($class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'id' => $id->toRfc4122(),
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param list<Uuid>      $ids
     *
     * @return list<T>
     */
    public function findManyForTenant(string $class, Uuid $tenantId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('e')->from($class, 'e')
            ->where('e.tenantId = :tenant AND e.id IN (:ids)')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('ids', array_map(static fn (Uuid $id) => $id->toRfc4122(), $ids))
            ->getQuery()->getResult();
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $criteria
     */
    public function existsForTenant(string $class, Uuid $tenantId, array $criteria, ?Uuid $exceptId = null): bool
    {
        $qb = $this->em->createQueryBuilder()
            ->select('1')
            ->from($class, 'e')
            ->where('e.tenantId = :tenant')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setMaxResults(1);

        $i = 0;
        foreach ($criteria as $field => $value) {
            $param = 'p'.$i++;
            $qb->andWhere(\sprintf('LOWER(e.%s) = LOWER(:%s)', $field, $param))->setParameter($param, $value);
        }

        if (null !== $exceptId) {
            $qb->andWhere('e.id <> :except')->setParameter('except', $exceptId->toRfc4122());
        }

        return [] !== $qb->getQuery()->getResult();
    }

    /**
     * @return list<Category>
     */
    public function listCategories(Uuid $tenantId, ?string $kind, ?bool $active): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')->from(Category::class, 'c')
            ->where('c.tenantId = :tenant')->setParameter('tenant', $tenantId->toRfc4122())
            ->orderBy('c.sortOrder', 'ASC')->addOrderBy('c.name', 'ASC');

        if (null !== $kind) {
            $qb->andWhere('c.kind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $active) {
            $qb->andWhere('c.active = :active')->setParameter('active', $active);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<UnitOfMeasure>
     */
    public function listUnits(Uuid $tenantId): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')->from(UnitOfMeasure::class, 'u')
            ->where('u.tenantId = :tenant')->setParameter('tenant', $tenantId->toRfc4122())
            ->orderBy('u.dimension', 'ASC')->addOrderBy('u.baseFactor', 'ASC')
            ->getQuery()->getResult();
    }

    public function isUnitInUse(Uuid $tenantId, Uuid $unitId): bool
    {
        $id = $unitId->toRfc4122();
        $tenant = $tenantId->toRfc4122();

        $inProducts = $this->em->createQueryBuilder()
            ->select('1')->from(Product::class, 'p')
            ->where('p.tenantId = :tenant AND p.saleUnitId = :unit')
            ->setParameter('tenant', $tenant)->setParameter('unit', $id)
            ->setMaxResults(1)->getQuery()->getResult();

        if ([] !== $inProducts) {
            return true;
        }

        $inIngredients = $this->em->createQueryBuilder()
            ->select('1')->from(Ingredient::class, 'i')
            ->where('i.tenantId = :tenant AND (i.stockUnitId = :unit OR i.purchaseUnitId = :unit)')
            ->setParameter('tenant', $tenant)->setParameter('unit', $id)
            ->setMaxResults(1)->getQuery()->getResult();

        return [] !== $inIngredients;
    }

    public function isCategoryInUse(Uuid $tenantId, Uuid $categoryId): bool
    {
        foreach ([Product::class, Ingredient::class] as $class) {
            $rows = $this->em->createQueryBuilder()
                ->select('1')->from($class, 'e')
                ->where('e.tenantId = :tenant AND e.categoryId = :category')
                ->setParameter('tenant', $tenantId->toRfc4122())
                ->setParameter('category', $categoryId->toRfc4122())
                ->setMaxResults(1)->getQuery()->getResult();
            if ([] !== $rows) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{q?: ?string, active?: ?bool, categoryId?: ?Uuid, productionType?: ?string, stockTracked?: ?bool} $filters
     *
     * @return array{items: list<Product>, total: int}
     */
    public function searchProducts(Uuid $tenantId, array $filters, int $offset, int $limit): array
    {
        $qb = $this->baseSearch(Product::class, $tenantId, $filters, ['sku', 'barcode']);
        if (null !== ($filters['productionType'] ?? null)) {
            $qb->andWhere('e.productionType = :ptype')->setParameter('ptype', $filters['productionType']);
        }
        if (null !== ($filters['stockTracked'] ?? null)) {
            $qb->andWhere('e.stockTracked = :tracked')->setParameter('tracked', $filters['stockTracked']);
        }

        return $this->paginate($qb, $offset, $limit);
    }

    /**
     * @param array{q?: ?string, active?: ?bool, categoryId?: ?Uuid} $filters
     *
     * @return array{items: list<Ingredient>, total: int}
     */
    public function searchIngredients(Uuid $tenantId, array $filters, int $offset, int $limit): array
    {
        return $this->paginate($this->baseSearch(Ingredient::class, $tenantId, $filters, ['sku']), $offset, $limit);
    }

    /**
     * @param class-string                                           $class
     * @param array{q?: ?string, active?: ?bool, categoryId?: ?Uuid} $filters
     * @param list<string>                                           $codeFields campos comparados por igualdade exata
     */
    private function baseSearch(string $class, Uuid $tenantId, array $filters, array $codeFields): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')->from($class, 'e')
            ->where('e.tenantId = :tenant')->setParameter('tenant', $tenantId->toRfc4122())
            ->orderBy('e.name', 'ASC');

        $q = trim((string) ($filters['q'] ?? ''));
        if ('' !== $q) {
            $or = ['LOWER(e.name) LIKE :q'];
            foreach ($codeFields as $field) {
                $or[] = \sprintf('LOWER(e.%s) = :qExact', $field);
            }
            $qb->andWhere(implode(' OR ', $or))
                ->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_\\').'%')
                ->setParameter('qExact', mb_strtolower($q));
        }

        if (null !== ($filters['active'] ?? null)) {
            $qb->andWhere('e.active = :active')->setParameter('active', $filters['active']);
        }

        if (null !== ($filters['categoryId'] ?? null)) {
            $qb->andWhere('e.categoryId = :category')->setParameter('category', $filters['categoryId']->toRfc4122());
        }

        return $qb;
    }

    /**
     * @return array{items: list<mixed>, total: int}
     */
    private function paginate(QueryBuilder $qb, int $offset, int $limit): array
    {
        $qb->setFirstResult($offset)->setMaxResults($limit);
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return ['items' => iterator_to_array($paginator, false), 'total' => \count($paginator)];
    }
}
