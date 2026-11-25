<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Domain\ProductionType;
use Symfony\Component\Uid\Uuid;

final readonly class ProductView
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public ProductionType $productionType,
        public UnitOfMeasure $saleUnit,
        public bool $active,
    ) {
    }
}
