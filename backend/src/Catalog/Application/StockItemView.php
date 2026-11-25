<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Domain\StockItemType;
use Symfony\Component\Uid\Uuid;

/**
 * Visão pública (somente leitura) de um item estocável, para outros módulos.
 * A unidade de estoque é a de estoque do ingrediente ou a de venda do produto.
 */
final readonly class StockItemView
{
    public function __construct(
        public StockItemType $type,
        public Uuid $id,
        public string $name,
        public ?string $sku,
        public UnitOfMeasure $stockUnit,
        public bool $stockTracked,
        public bool $active,
    ) {
    }
}
