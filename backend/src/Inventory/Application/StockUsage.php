<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Catalog\Application\StockUsageChecker;
use App\Catalog\Domain\StockItemType;
use App\Inventory\Infrastructure\StockRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Implementa a porta do Catalog que protege o ledger contra mudanças de
 * unidade de estoque e desligamento do controle com saldo.
 */
final class StockUsage implements StockUsageChecker
{
    public function __construct(private readonly StockRepository $repository)
    {
    }

    public function hasMovements(Uuid $tenantId, StockItemType $type, Uuid $itemId): bool
    {
        return $this->repository->hasMovements($tenantId, $type, $itemId);
    }

    public function hasStock(Uuid $tenantId, StockItemType $type, Uuid $itemId): bool
    {
        return $this->repository->hasStock($tenantId, $type, $itemId);
    }
}
