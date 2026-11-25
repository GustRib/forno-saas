<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\ProductionType;
use Symfony\Component\Uid\Uuid;

/**
 * Dados validados para criar/alterar um produto.
 */
final readonly class ProductData
{
    public function __construct(
        public string $name,
        public Uuid $saleUnitId,
        public ProductionType $productionType,
        public string $salePrice,
        public ?Uuid $categoryId = null,
        public ?string $sku = null,
        public ?string $barcode = null,
        public ?string $description = null,
        public bool $stockTracked = true,
        public bool $active = true,
    ) {
    }
}
