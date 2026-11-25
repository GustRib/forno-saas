<?php

declare(strict_types=1);

namespace App\Recipe\Domain;

use App\Catalog\Domain\Model\UnitOfMeasure;

/**
 * Entrada do cálculo: um item da receita com o custo vigente do ingrediente.
 * `stockUnit`/`unitCost` nulos = ingrediente não encontrado.
 */
final readonly class CostLine
{
    public function __construct(
        public string $ingredientId,
        public ?string $ingredientName,
        public string $quantity,
        public UnitOfMeasure $unit,
        public ?UnitOfMeasure $stockUnit,
        public ?string $unitCost,
        public bool $ingredientActive = true,
        public ?\DateTimeImmutable $costChangedAt = null,
        public ?string $costSource = null,
    ) {
    }
}
