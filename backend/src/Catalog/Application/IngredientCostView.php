<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\CostSource;
use App\Catalog\Domain\Model\UnitOfMeasure;
use Symfony\Component\Uid\Uuid;

/**
 * Custo vigente de um ingrediente, por unidade de estoque, com a data e a
 * origem da última mudança (FR-025).
 */
final readonly class IngredientCostView
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public UnitOfMeasure $stockUnit,
        public string $averageUnitCost,
        public bool $active,
        public ?\DateTimeImmutable $costChangedAt,
        public ?CostSource $costSource,
    ) {
    }
}
