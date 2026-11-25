<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * Dados validados para criar/alterar um ingrediente.
 */
final readonly class IngredientData
{
    public function __construct(
        public string $name,
        public Uuid $stockUnitId,
        public string $averageUnitCost = '0',
        public string $minimumStock = '0',
        public ?Uuid $categoryId = null,
        public ?string $sku = null,
        public ?Uuid $purchaseUnitId = null,
        public ?string $purchaseToStockFactor = null,
        public bool $active = true,
    ) {
    }
}
