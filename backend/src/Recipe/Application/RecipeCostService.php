<?php

declare(strict_types=1);

namespace App\Recipe\Application;

use App\Catalog\Application\RecipeCatalog;
use App\Recipe\Domain\CostLine;
use App\Recipe\Domain\Model\Recipe;
use App\Recipe\Domain\RecipeCost;
use App\Recipe\Domain\RecipeCostCalculator;

/**
 * Monta o cálculo de custo de uma versão com os custos vigentes dos
 * ingredientes (estimativa "ao vivo", recalculada a cada leitura).
 */
final class RecipeCostService
{
    public function __construct(
        private readonly RecipeCatalog $catalog,
        private readonly RecipeCostCalculator $calculator,
    ) {
    }

    public function calculate(Recipe $recipe): RecipeCost
    {
        $tenantId = $recipe->getTenantId();
        $units = $this->catalog->units($tenantId);
        $items = $recipe->getItems();
        $ingredients = $this->catalog->ingredients($tenantId, array_map(static fn ($i) => $i->getIngredientId(), $items));
        $product = $this->catalog->product($tenantId, $recipe->getProductId());

        $lines = [];
        foreach ($items as $item) {
            $ingredient = $ingredients[$item->getIngredientId()->toRfc4122()] ?? null;
            $lines[] = new CostLine(
                ingredientId: $item->getIngredientId()->toRfc4122(),
                ingredientName: $ingredient?->name,
                quantity: $item->getQuantity(),
                unit: $units[$item->getUnitId()->toRfc4122()],
                stockUnit: $ingredient?->stockUnit,
                unitCost: $ingredient?->averageUnitCost,
                ingredientActive: $ingredient->active ?? false,
                costChangedAt: $ingredient?->costChangedAt,
                costSource: $ingredient?->costSource?->value,
            );
        }

        return $this->calculator->calculate(
            $lines,
            array_map(static fn ($c) => $c->getAmount(), $recipe->getAdditionalCosts()),
            $recipe->getExpectedYieldQty(),
            $units[$recipe->getYieldUnitId()->toRfc4122()],
            $product?->saleUnit,
        );
    }
}
