<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\Ingredient;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Domain\StockItemType;
use App\Catalog\Infrastructure\CatalogRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Caso de uso público do Catalog para o módulo Inventory: localizar e listar
 * itens estocáveis sem expor as entidades nem as tabelas do catálogo.
 * O tenant é sempre explícito.
 */
final class StockItemCatalog
{
    public function __construct(private readonly CatalogRepository $repository)
    {
    }

    public function find(Uuid $tenantId, StockItemType $type, Uuid $id): ?StockItemView
    {
        $item = match ($type) {
            StockItemType::INGREDIENT => $this->repository->findForTenant(Ingredient::class, $tenantId, $id),
            StockItemType::PRODUCT => $this->repository->findForTenant(Product::class, $tenantId, $id),
        };
        if (null === $item) {
            return null;
        }

        return $this->toView($tenantId, $item, $this->unitsById($tenantId));
    }

    /**
     * Itens que controlam estoque (todos os ingredientes; produtos com
     * stockTracked), por nome. Inclui inativos quando $activeOnly = false.
     *
     * @return array{items: list<StockItemView>, total: int}
     */
    public function search(Uuid $tenantId, StockItemType $type, ?string $q, bool $activeOnly, int $offset, int $limit): array
    {
        $filters = ['q' => $q, 'active' => $activeOnly ? true : null];
        $result = match ($type) {
            StockItemType::INGREDIENT => $this->repository->searchIngredients($tenantId, $filters, $offset, $limit),
            StockItemType::PRODUCT => $this->repository->searchProducts($tenantId, [...$filters, 'stockTracked' => true], $offset, $limit),
        };

        $units = $this->unitsById($tenantId);

        return [
            'items' => array_map(fn (Ingredient|Product $i) => $this->toView($tenantId, $i, $units), $result['items']),
            'total' => $result['total'],
        ];
    }

    /**
     * @param array<string, UnitOfMeasure> $units
     */
    private function toView(Uuid $tenantId, Ingredient|Product $item, array $units): StockItemView
    {
        $unitId = $item instanceof Ingredient ? $item->getStockUnitId() : $item->getSaleUnitId();
        $unit = $units[$unitId->toRfc4122()] ?? throw new \LogicException('Unidade de estoque inexistente para o item.');

        return new StockItemView(
            type: $item instanceof Ingredient ? StockItemType::INGREDIENT : StockItemType::PRODUCT,
            id: $item->getId(),
            name: $item->getName(),
            sku: $item->getSku(),
            stockUnit: $unit,
            stockTracked: $item instanceof Ingredient || $item->isStockTracked(),
            active: $item->isActive(),
        );
    }

    /**
     * @return array<string, UnitOfMeasure>
     */
    private function unitsById(Uuid $tenantId): array
    {
        $units = [];
        foreach ($this->repository->listUnits($tenantId) as $unit) {
            $units[$unit->getId()->toRfc4122()] = $unit;
        }

        return $units;
    }
}
