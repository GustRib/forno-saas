<?php

declare(strict_types=1);

namespace App\Inventory\Presentation;

use App\Catalog\Application\StockItemView;
use App\Identity\Domain\Model\User;
use App\Inventory\Domain\Model\StockLocation;
use App\Inventory\Domain\Model\StockMovement;

/**
 * Representação pública do estoque. Quantidades saem como string decimal na
 * unidade de estoque do item, sem zeros à direita.
 */
final class InventorySerializer
{
    /**
     * @return array<string, mixed>
     */
    public function location(StockLocation $l): array
    {
        return ['id' => $l->getId()->toRfc4122(), 'name' => $l->getName(), 'isDefault' => $l->isDefault()];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(StockItemView $i): array
    {
        return [
            'itemType' => $i->type->value,
            'itemId' => $i->id->toRfc4122(),
            'name' => $i->name,
            'sku' => $i->sku,
            'active' => $i->active,
            'unit' => [
                'id' => $i->stockUnit->getId()->toRfc4122(),
                'code' => $i->stockUnit->getCode(),
                'name' => $i->stockUnit->getName(),
                'dimension' => $i->stockUnit->getDimension()->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function movement(StockMovement $m, ?User $user = null, bool $reversed = false): array
    {
        $type = $m->getMovementType();

        return [
            'id' => $m->getId()->toRfc4122(),
            'itemType' => $m->getItemType()->value,
            'itemId' => $m->getItemId()->toRfc4122(),
            'locationId' => $m->getStockLocationId()->toRfc4122(),
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'quantityDelta' => self::decimal($m->getQuantityDelta()),
            'unitId' => $m->getUnitId()->toRfc4122(),
            'unitCost' => null !== $m->getUnitCost() ? self::decimal($m->getUnitCost()) : null,
            'reason' => $m->getReason(),
            'sourceType' => $m->getSourceType(),
            'sourceId' => $m->getSourceId()?->toRfc4122(),
            'reversalOfId' => $m->getReversalOfId()?->toRfc4122(),
            'reversed' => $reversed,
            'reversible' => $type->isManuallyReversible() && !$reversed,
            'occurredAt' => $m->getOccurredAt()->format(\DATE_ATOM),
            'createdAt' => $m->getCreatedAt()->format(\DATE_ATOM),
            'createdBy' => ['id' => $m->getCreatedBy()->toRfc4122(), 'name' => $user?->getName()],
        ];
    }

    public static function decimal(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return '-0' === $trimmed ? '0' : $trimmed;
    }
}
