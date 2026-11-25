<?php

declare(strict_types=1);

namespace App\Catalog\Presentation;

use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\Ingredient;
use App\Catalog\Domain\Model\IngredientCostChange;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Identity\Domain\Model\User;

/**
 * Representação pública das entidades do catálogo. Entidades Doctrine nunca
 * são expostas diretamente (ARCHITECTURE §10). Decimais saem como string.
 */
final class CatalogSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function category(Category $c): array
    {
        return [
            'id' => $c->getId()->toRfc4122(),
            'name' => $c->getName(),
            'kind' => $c->getKind()->value,
            'sortOrder' => $c->getSortOrder(),
            'active' => $c->isActive(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function unit(UnitOfMeasure $u): array
    {
        return [
            'id' => $u->getId()->toRfc4122(),
            'code' => $u->getCode(),
            'name' => $u->getName(),
            'dimension' => $u->getDimension()->value,
            'dimensionLabel' => $u->getDimension()->label(),
            'baseFactor' => $this->trimZeros($u->getBaseFactor()),
            'isBase' => $u->isBase(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function product(Product $p): array
    {
        return [
            'id' => $p->getId()->toRfc4122(),
            'name' => $p->getName(),
            'categoryId' => $p->getCategoryId()?->toRfc4122(),
            'sku' => $p->getSku(),
            'barcode' => $p->getBarcode(),
            'description' => $p->getDescription(),
            'saleUnitId' => $p->getSaleUnitId()->toRfc4122(),
            'stockTracked' => $p->isStockTracked(),
            'productionType' => $p->getProductionType()->value,
            'salePrice' => $p->getSalePrice(),
            'active' => $p->isActive(),
            'createdAt' => $p->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $p->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ingredient(Ingredient $i): array
    {
        return [
            'id' => $i->getId()->toRfc4122(),
            'name' => $i->getName(),
            'categoryId' => $i->getCategoryId()?->toRfc4122(),
            'sku' => $i->getSku(),
            'stockUnitId' => $i->getStockUnitId()->toRfc4122(),
            'purchaseUnitId' => $i->getPurchaseUnitId()?->toRfc4122(),
            'purchaseToStockFactor' => null !== $i->getPurchaseToStockFactor() ? $this->trimZeros($i->getPurchaseToStockFactor()) : null,
            'averageUnitCost' => $this->trimZeros($i->getAverageUnitCost()),
            'minimumStock' => $this->trimZeros($i->getMinimumStock()),
            'active' => $i->isActive(),
            'createdAt' => $i->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $i->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function costChange(IngredientCostChange $c, ?User $user): array
    {
        return [
            'id' => $c->getId()->toRfc4122(),
            'previousCost' => null !== $c->getPreviousCost() ? $this->trimZeros($c->getPreviousCost()) : null,
            'newCost' => $this->trimZeros($c->getNewCost()),
            'source' => $c->getSourceType()->value,
            'sourceLabel' => $c->getSourceType()->label(),
            'stockMovementId' => $c->getStockMovementId()?->toRfc4122(),
            'costPolicy' => $c->getCostPolicy(),
            'changedAt' => $c->getChangedAt()->format(\DATE_ATOM),
            'changedBy' => null !== $c->getChangedBy() ? ['id' => $c->getChangedBy()->toRfc4122(), 'name' => $user?->getName()] : null,
        ];
    }

    /**
     * "1000.000000000" → "1000"; "0.500000" → "0.5". Só apresentação.
     */
    private function trimZeros(string $decimal): string
    {
        if (!str_contains($decimal, '.')) {
            return $decimal;
        }

        return rtrim(rtrim($decimal, '0'), '.');
    }
}
