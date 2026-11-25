<?php

declare(strict_types=1);

namespace App\Recipe\Infrastructure;

use App\Recipe\Domain\Model\Recipe;
use App\Recipe\Domain\Model\RecipeCostSnapshot;
use App\Recipe\Domain\RecipeStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persistência do módulo Recipe. Toda consulta recebe o tenant.
 */
final class RecipeRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    public function add(object $entity): void
    {
        $this->em->persist($entity);
    }

    public function remove(Recipe $recipe): void
    {
        $this->em->remove($recipe);
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    public function find(Uuid $tenantId, Uuid $id): ?Recipe
    {
        return $this->em->getRepository(Recipe::class)->findOneBy(['tenantId' => $tenantId->toRfc4122(), 'id' => $id->toRfc4122()]);
    }

    public function findByStatus(Uuid $tenantId, Uuid $productId, RecipeStatus $status): ?Recipe
    {
        return $this->em->getRepository(Recipe::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'productId' => $productId->toRfc4122(),
            'status' => $status,
        ]);
    }

    public function nextVersion(Uuid $tenantId, Uuid $productId): int
    {
        return 1 + (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(version), 0) FROM recipes WHERE tenant_id = ? AND product_id = ?',
            [$tenantId->toRfc4122(), $productId->toRfc4122()],
        );
    }

    /**
     * @return list<Recipe> mais recente primeiro
     */
    public function versions(Uuid $tenantId, Uuid $productId): array
    {
        return $this->em->getRepository(Recipe::class)->findBy(
            ['tenantId' => $tenantId->toRfc4122(), 'productId' => $productId->toRfc4122()],
            ['version' => 'DESC'],
        );
    }

    /**
     * @return array{items: list<Recipe>, total: int}
     */
    public function search(Uuid $tenantId, ?Uuid $productId, ?RecipeStatus $status, int $offset, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r')->from(Recipe::class, 'r')
            ->where('r.tenantId = :tenant')->setParameter('tenant', $tenantId->toRfc4122());
        if (null !== $productId) {
            $qb->andWhere('r.productId = :product')->setParameter('product', $productId->toRfc4122());
        }
        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('r.name', 'ASC')->addOrderBy('r.version', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<RecipeCostSnapshot> mais recente primeiro
     */
    public function snapshots(Uuid $tenantId, Uuid $recipeId): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')->from(RecipeCostSnapshot::class, 's')
            ->where('s.tenantId = :tenant AND s.recipeId = :recipe')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('recipe', $recipeId->toRfc4122())
            ->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')
            ->getQuery()->getResult();
    }

    public function isIngredientInOpenRecipe(Uuid $tenantId, Uuid $ingredientId): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM recipe_items i JOIN recipes r ON r.id = i.recipe_id
             WHERE i.tenant_id = ? AND i.ingredient_id = ? AND r.status IN ('DRAFT', 'ACTIVE') LIMIT 1",
            [$tenantId->toRfc4122(), $ingredientId->toRfc4122()],
        );
    }

    public function productHasOpenRecipe(Uuid $tenantId, Uuid $productId): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM recipes WHERE tenant_id = ? AND product_id = ? AND status IN ('DRAFT', 'ACTIVE') LIMIT 1",
            [$tenantId->toRfc4122(), $productId->toRfc4122()],
        );
    }
}
