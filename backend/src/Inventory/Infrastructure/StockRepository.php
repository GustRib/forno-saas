<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure;

use App\Catalog\Domain\StockItemType;
use App\Inventory\Domain\Model\StockLocation;
use App\Inventory\Domain\Model\StockMovement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persistência do módulo Inventory. Toda consulta recebe o tenant.
 *
 * `stock_balances` é mantida pelo trigger `trg_stock_movements_apply` a cada
 * INSERT no ledger; a aplicação só a lê (e a bloqueia para serializar
 * operações concorrentes sobre o mesmo item).
 */
final class StockRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    public function addLocation(StockLocation $location): void
    {
        $this->em->persist($location);
    }

    public function addMovement(StockMovement $movement): void
    {
        $this->em->persist($movement);
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    public function defaultLocation(Uuid $tenantId): ?StockLocation
    {
        return $this->em->getRepository(StockLocation::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'isDefault' => true,
        ]);
    }

    public function findLocation(Uuid $tenantId, Uuid $id): ?StockLocation
    {
        return $this->em->getRepository(StockLocation::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'id' => $id->toRfc4122(),
        ]);
    }

    /**
     * @return list<StockLocation>
     */
    public function listLocations(Uuid $tenantId): array
    {
        return $this->em->getRepository(StockLocation::class)->findBy(
            ['tenantId' => $tenantId->toRfc4122()],
            ['isDefault' => 'DESC', 'name' => 'ASC'],
        );
    }

    public function findMovement(Uuid $tenantId, Uuid $id): ?StockMovement
    {
        return $this->em->getRepository(StockMovement::class)->findOneBy([
            'tenantId' => $tenantId->toRfc4122(),
            'id' => $id->toRfc4122(),
        ]);
    }

    public function isReversed(Uuid $tenantId, Uuid $movementId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM stock_movements WHERE tenant_id = ? AND reversal_of_id = ?',
            [$tenantId->toRfc4122(), $movementId->toRfc4122()],
        );
    }

    /**
     * Garante a linha de saldo e a bloqueia (SELECT … FOR UPDATE) até o fim da
     * transação. Operações concorrentes sobre o mesmo item/local esperam aqui,
     * então a verificação de saldo suficiente não sofre corrida.
     *
     * @return string saldo atual na unidade de estoque
     */
    public function lockBalance(Uuid $tenantId, StockItemType $type, Uuid $itemId, Uuid $locationId, Uuid $unitId): string
    {
        $column = $this->itemColumn($type);
        $params = [$tenantId->toRfc4122(), $itemId->toRfc4122(), $locationId->toRfc4122()];

        $this->connection->executeStatement(
            "INSERT INTO stock_balances (tenant_id, {$column}, stock_location_id, unit_id, quantity)
             VALUES (?, ?, ?, ?, 0)
             ON CONFLICT (tenant_id, stock_location_id, {$column}) WHERE {$column} IS NOT NULL DO NOTHING",
            [...$params, $unitId->toRfc4122()],
        );

        return (string) $this->connection->fetchOne(
            "SELECT quantity FROM stock_balances
             WHERE tenant_id = ? AND {$column} = ? AND stock_location_id = ?
             FOR UPDATE",
            $params,
        );
    }

    public function hasMovements(Uuid $tenantId, StockItemType $type, Uuid $itemId, ?Uuid $locationId = null): bool
    {
        $column = $this->itemColumn($type);
        $sql = "SELECT 1 FROM stock_movements WHERE tenant_id = ? AND {$column} = ?";
        $params = [$tenantId->toRfc4122(), $itemId->toRfc4122()];
        if (null !== $locationId) {
            $sql .= ' AND stock_location_id = ?';
            $params[] = $locationId->toRfc4122();
        }

        return false !== $this->connection->fetchOne($sql.' LIMIT 1', $params);
    }

    /**
     * Saldo total do item somando todos os locais (base do custo médio WAC-1).
     * Chamar com o item bloqueado para leitura consistente.
     */
    public function totalQuantity(Uuid $tenantId, StockItemType $type, Uuid $itemId): string
    {
        $column = $this->itemColumn($type);

        return (string) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(quantity), 0) FROM stock_balances WHERE tenant_id = ? AND {$column} = ?",
            [$tenantId->toRfc4122(), $itemId->toRfc4122()],
        );
    }

    public function hasStock(Uuid $tenantId, StockItemType $type, Uuid $itemId): bool
    {
        $column = $this->itemColumn($type);

        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM stock_balances WHERE tenant_id = ? AND {$column} = ? AND quantity <> 0 LIMIT 1",
            [$tenantId->toRfc4122(), $itemId->toRfc4122()],
        );
    }

    /**
     * Saldos de vários itens num local, indexados pelo UUID do item.
     * Itens sem linha de saldo não aparecem (saldo zero).
     *
     * @param list<Uuid> $itemIds
     *
     * @return array<string, string>
     */
    public function balancesFor(Uuid $tenantId, StockItemType $type, array $itemIds, Uuid $locationId): array
    {
        if ([] === $itemIds) {
            return [];
        }
        $column = $this->itemColumn($type);

        $rows = $this->connection->fetchAllKeyValue(
            "SELECT {$column}, quantity FROM stock_balances
             WHERE tenant_id = :tenant AND stock_location_id = :location AND {$column} IN (:ids)",
            [
                'tenant' => $tenantId->toRfc4122(),
                'location' => $locationId->toRfc4122(),
                'ids' => array_map(static fn (Uuid $id) => $id->toRfc4122(), $itemIds),
            ],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn ($q) => (string) $q, $rows);
    }

    /**
     * Saldo do item em cada local onde ele tem linha de saldo.
     *
     * @return list<array{locationId: string, quantity: string}>
     */
    public function balancesByLocation(Uuid $tenantId, StockItemType $type, Uuid $itemId): array
    {
        $column = $this->itemColumn($type);

        /** @var list<array{locationId: string, quantity: string}> */
        return $this->connection->fetchAllAssociative(
            "SELECT stock_location_id AS \"locationId\", quantity FROM stock_balances
             WHERE tenant_id = ? AND {$column} = ?",
            [$tenantId->toRfc4122(), $itemId->toRfc4122()],
        );
    }

    /**
     * Histórico do item, mais recente primeiro.
     *
     * @return array{items: list<StockMovement>, total: int}
     */
    public function movementsFor(Uuid $tenantId, StockItemType $type, Uuid $itemId, ?Uuid $locationId, int $offset, int $limit): array
    {
        $field = StockItemType::INGREDIENT === $type ? 'ingredientId' : 'productId';
        $qb = $this->em->createQueryBuilder()
            ->select('m')->from(StockMovement::class, 'm')
            ->where('m.tenantId = :tenant')
            ->andWhere("m.{$field} = :item")
            ->setParameter('tenant', $tenantId->toRfc4122())
            ->setParameter('item', $itemId->toRfc4122());
        if (null !== $locationId) {
            $qb->andWhere('m.stockLocationId = :location')->setParameter('location', $locationId->toRfc4122());
        }

        $total = (int) (clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        // Datas são gravadas com precisão de segundos; o UUID v7 (ordenado no
        // tempo e monotônico) desempata lançamentos do mesmo segundo.
        $items = $qb->orderBy('m.occurredAt', 'DESC')->addOrderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * IDs (dentre os informados) que já foram estornados.
     *
     * @param list<Uuid> $movementIds
     *
     * @return array<string, true>
     */
    public function reversedAmong(Uuid $tenantId, array $movementIds): array
    {
        if ([] === $movementIds) {
            return [];
        }

        $ids = $this->connection->fetchFirstColumn(
            'SELECT reversal_of_id FROM stock_movements WHERE tenant_id = :tenant AND reversal_of_id IN (:ids)',
            ['tenant' => $tenantId->toRfc4122(), 'ids' => array_map(static fn (Uuid $id) => $id->toRfc4122(), $movementIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map('strval', $ids), true);
    }

    /**
     * Linhas em que o saldo materializado difere da soma do ledger
     * (deveria ser sempre vazio). Usado pelo comando forno:stock:verify.
     *
     * @return list<array<string, mixed>>
     */
    public function findBalanceMismatches(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            WITH ledger AS (
                SELECT tenant_id, ingredient_id, product_id, stock_location_id, SUM(quantity_delta) AS total
                FROM stock_movements
                GROUP BY tenant_id, ingredient_id, product_id, stock_location_id
            )
            SELECT COALESCE(b.tenant_id, l.tenant_id) AS tenant_id,
                   COALESCE(b.ingredient_id, l.ingredient_id) AS ingredient_id,
                   COALESCE(b.product_id, l.product_id) AS product_id,
                   COALESCE(b.stock_location_id, l.stock_location_id) AS stock_location_id,
                   COALESCE(b.quantity, 0) AS balance,
                   COALESCE(l.total, 0) AS ledger
            FROM stock_balances b
            FULL OUTER JOIN ledger l
              ON l.tenant_id = b.tenant_id
             AND l.stock_location_id = b.stock_location_id
             AND l.ingredient_id IS NOT DISTINCT FROM b.ingredient_id
             AND l.product_id IS NOT DISTINCT FROM b.product_id
            WHERE COALESCE(b.quantity, 0) <> COALESCE(l.total, 0)
            SQL);
    }

    private function itemColumn(StockItemType $type): string
    {
        return StockItemType::INGREDIENT === $type ? 'ingredient_id' : 'product_id';
    }
}
