<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Inventory\Domain\Model\StockLocation;
use App\Inventory\Infrastructure\StockRepository;
use App\Tenant\Domain\Event\TenantCreated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * DATABASE §3.12: no MVP cada estabelecimento tem um local padrão.
 * Roda na transação de criação do tenant.
 */
#[AsEventListener]
final class CreateDefaultLocationOnTenantCreated
{
    public function __construct(private readonly StockRepository $repository)
    {
    }

    public function __invoke(TenantCreated $event): void
    {
        $this->repository->addLocation(new StockLocation($event->tenantId, StockLocation::DEFAULT_NAME, true));
    }
}
