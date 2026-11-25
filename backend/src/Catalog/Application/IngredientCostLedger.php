<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\CostSource;
use App\Catalog\Domain\Model\Ingredient;
use App\Catalog\Domain\Model\IngredientCostChange;
use App\Shared\Domain\CostPolicy;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Caso de uso público do Catalog para o custo dos ingredientes.
 *
 * O custo vive no cadastro (`ingredients.average_unit_cost`, FR-014); quem o
 * recalcula pela política WAC-1 é o módulo Inventory, que chama este serviço
 * dentro da sua transação. Toda mudança gera uma linha em
 * `ingredient_cost_history` (data e origem — FR-025). Não faz flush.
 */
final class IngredientCostLedger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Bloqueia o ingrediente (SELECT … FOR UPDATE) e devolve o custo atual
     * relido do banco. Serializa todas as mudanças de custo do item; exige
     * transação aberta.
     */
    public function lockCurrentCost(Uuid $tenantId, Uuid $ingredientId): string
    {
        $ingredient = $this->lock($tenantId, $ingredientId);

        return $ingredient->getAverageUnitCost();
    }

    /**
     * Custo recalculado por um movimento de estoque (política WAC-1).
     * Sem efeito se o custo não mudou.
     */
    public function recordMovementCost(Uuid $tenantId, Uuid $ingredientId, string $newCost, Uuid $movementId, Uuid $userId): void
    {
        $ingredient = $this->find($tenantId, $ingredientId);
        $this->change($ingredient, $newCost, CostSource::STOCK_MOVEMENT, $userId, $movementId, CostPolicy::CURRENT);
    }

    /**
     * Custo informado no cadastro (criação ou edição do ingrediente).
     */
    public function recordManualCost(Ingredient $ingredient, ?string $previousCost, Uuid $userId): void
    {
        if (null !== $previousCost && 0 === Decimal::compare($previousCost, $ingredient->getAverageUnitCost())) {
            return;
        }

        $this->em->persist(new IngredientCostChange(
            tenantId: $ingredient->getTenantId(),
            ingredientId: $ingredient->getId(),
            previousCost: $previousCost,
            newCost: Decimal::round($ingredient->getAverageUnitCost(), CostPolicy::UNIT_SCALE),
            sourceType: CostSource::MANUAL,
            changedBy: $userId,
        ));
    }

    /**
     * @return array{items: list<IngredientCostChange>, total: int}
     */
    public function history(Uuid $tenantId, Uuid $ingredientId, int $offset, int $limit): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('h')->from(IngredientCostChange::class, 'h')
            ->where('h.tenantId = :tenant AND h.ingredientId = :ingredient')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('ingredient', $ingredientId->toRfc4122());

        $total = (int) (clone $qb)->select('COUNT(h.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->orderBy('h.changedAt', 'DESC')->addOrderBy('h.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Última mudança de custo de cada ingrediente (para mostrar data e
     * origem do custo usado no cálculo — FR-025).
     *
     * @param list<Uuid> $ingredientIds
     *
     * @return array<string, IngredientCostChange>
     */
    public function latestChanges(Uuid $tenantId, array $ingredientIds): array
    {
        if ([] === $ingredientIds) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('h')->from(IngredientCostChange::class, 'h')
            ->where('h.tenantId = :tenant AND h.ingredientId IN (:ids)')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('ids', array_map(static fn (Uuid $id) => $id->toRfc4122(), $ingredientIds))
            ->orderBy('h.changedAt', 'ASC')->addOrderBy('h.id', 'ASC')
            ->getQuery()->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->getIngredientId()->toRfc4122()] = $row;
        }

        return $latest;
    }

    private function change(Ingredient $ingredient, string $newCost, CostSource $source, Uuid $userId, ?Uuid $movementId, ?string $policy): void
    {
        $newCost = Decimal::round($newCost, CostPolicy::UNIT_SCALE);
        $previous = $ingredient->getAverageUnitCost();
        if (0 === Decimal::compare($previous, $newCost)) {
            return;
        }

        $ingredient->changeAverageCost($newCost);
        $this->em->persist(new IngredientCostChange(
            tenantId: $ingredient->getTenantId(),
            ingredientId: $ingredient->getId(),
            previousCost: $previous,
            newCost: $newCost,
            sourceType: $source,
            changedBy: $userId,
            stockMovementId: $movementId,
            costPolicy: $policy,
        ));
    }

    private function lock(Uuid $tenantId, Uuid $ingredientId): Ingredient
    {
        $ingredient = $this->em->createQueryBuilder()
            ->select('i')->from(Ingredient::class, 'i')
            ->where('i.tenantId = :tenant AND i.id = :id')
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('id', $ingredientId->toRfc4122())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            // A entidade pode já estar carregada sem bloqueio: relê do banco.
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $ingredient ?? throw DomainException::notFound('Ingrediente não encontrado.');
    }

    private function find(Uuid $tenantId, Uuid $ingredientId): Ingredient
    {
        return $this->em->getRepository(Ingredient::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'id' => $ingredientId->toRfc4122(),
        ]) ?? throw DomainException::notFound('Ingrediente não encontrado.');
    }
}
