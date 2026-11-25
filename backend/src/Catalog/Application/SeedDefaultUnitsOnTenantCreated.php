<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Dimension;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Infrastructure\CatalogRepository;
use App\Tenant\Domain\Event\TenantCreated;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Cria as unidades padrão de uma padaria quando um estabelecimento nasce.
 * Roda na mesma transação da criação do tenant.
 */
#[AsEventListener]
final class SeedDefaultUnitsOnTenantCreated
{
    /** @var list<array{string, string, Dimension, string, bool}> */
    public const DEFAULTS = [
        ['G', 'Grama', Dimension::MASS, '1', true],
        ['KG', 'Quilograma', Dimension::MASS, '1000', false],
        ['ML', 'Mililitro', Dimension::VOLUME, '1', true],
        ['L', 'Litro', Dimension::VOLUME, '1000', false],
        ['UN', 'Unidade', Dimension::COUNT, '1', true],
        ['DZ', 'Dúzia', Dimension::COUNT, '12', false],
    ];

    public function __construct(private readonly CatalogRepository $repository)
    {
    }

    public function __invoke(TenantCreated $event): void
    {
        foreach (self::DEFAULTS as [$code, $name, $dimension, $factor, $isBase]) {
            $this->repository->add(new UnitOfMeasure($event->tenantId, $code, $name, $dimension, $factor, $isBase));
        }
    }
}
