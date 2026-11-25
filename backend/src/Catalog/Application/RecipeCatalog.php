<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\Ingredient;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Infrastructure\CatalogRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Caso de uso público do Catalog para o módulo Recipe: produtos, ingredientes
 * (com custo vigente, data e origem — FR-025) e unidades, sempre por tenant.
 */
final class RecipeCatalog
{
    public function __construct(
        private readonly CatalogRepository $repository,
        private readonly IngredientCostLedger $costs,
    ) {
    }

    public function product(Uuid $tenantId, Uuid $productId): ?ProductView
    {
        return $this->products($tenantId, [$productId])[$productId->toRfc4122()] ?? null;
    }

    /**
     * @param list<Uuid> $productIds
     *
     * @return array<string, ProductView>
     */
    public function products(Uuid $tenantId, array $productIds): array
    {
        $units = $this->units($tenantId);
        $views = [];
        foreach ($this->repository->findManyForTenant(Product::class, $tenantId, $productIds) as $product) {
            $views[$product->getId()->toRfc4122()] = new ProductView(
                id: $product->getId(),
                name: $product->getName(),
                productionType: $product->getProductionType(),
                saleUnit: $units[$product->getSaleUnitId()->toRfc4122()],
                active: $product->isActive(),
            );
        }

        return $views;
    }

    /**
     * @param list<Uuid> $ingredientIds
     *
     * @return array<string, IngredientCostView>
     */
    public function ingredients(Uuid $tenantId, array $ingredientIds): array
    {
        $units = $this->units($tenantId);
        $latest = $this->costs->latestChanges($tenantId, $ingredientIds);

        $views = [];
        foreach ($this->repository->findManyForTenant(Ingredient::class, $tenantId, $ingredientIds) as $ingredient) {
            $key = $ingredient->getId()->toRfc4122();
            $change = $latest[$key] ?? null;
            $views[$key] = new IngredientCostView(
                id: $ingredient->getId(),
                name: $ingredient->getName(),
                stockUnit: $units[$ingredient->getStockUnitId()->toRfc4122()],
                averageUnitCost: $ingredient->getAverageUnitCost(),
                active: $ingredient->isActive(),
                costChangedAt: $change?->getChangedAt(),
                costSource: $change?->getSourceType(),
            );
        }

        return $views;
    }

    public function unit(Uuid $tenantId, Uuid $unitId): ?UnitOfMeasure
    {
        return $this->units($tenantId)[$unitId->toRfc4122()] ?? null;
    }

    /**
     * @return array<string, UnitOfMeasure>
     */
    public function units(Uuid $tenantId): array
    {
        $units = [];
        foreach ($this->repository->listUnits($tenantId) as $unit) {
            $units[$unit->getId()->toRfc4122()] = $unit;
        }

        return $units;
    }
}
