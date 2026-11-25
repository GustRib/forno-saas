<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\StockItemType;
use Symfony\Component\Uid\Uuid;

/**
 * Porta implementada pelo módulo Inventory. O Catalog a usa para impedir
 * alterações que reinterpretariam o ledger (ex.: trocar a unidade de estoque
 * de um item que já tem movimentações).
 */
interface StockUsageChecker
{
    public function hasMovements(Uuid $tenantId, StockItemType $type, Uuid $itemId): bool;

    public function hasStock(Uuid $tenantId, StockItemType $type, Uuid $itemId): bool;
}
