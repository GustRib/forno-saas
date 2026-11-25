<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Catalog\Domain\StockItemType;
use Symfony\Component\Uid\Uuid;

/**
 * Dados validados de um lançamento manual. `quantity` é sempre positiva; o
 * sinal vem do tipo. Se `unitId` for nulo, a quantidade está na unidade de
 * estoque do item; senão é convertida (sem arredondamento).
 */
final readonly class MovementInput
{
    public function __construct(
        public StockItemType $itemType,
        public Uuid $itemId,
        public string $quantity,
        public ?Uuid $unitId = null,
        public ?Uuid $locationId = null,
        public ?string $reason = null,
        public ?string $unitCost = null,
        public ?\DateTimeImmutable $occurredAt = null,
    ) {
    }
}
