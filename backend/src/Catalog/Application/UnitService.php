<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Dimension;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Domain\UnitConverter;
use App\Catalog\Infrastructure\CatalogRepository;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Symfony\Component\Uid\Uuid;

final class UnitService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CatalogRepository $repository,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * @return list<UnitOfMeasure>
     */
    public function list(): array
    {
        return $this->repository->listUnits($this->context->getTenantId());
    }

    public function get(Uuid $id): UnitOfMeasure
    {
        return $this->repository->findForTenant(UnitOfMeasure::class, $this->context->getTenantId(), $id)
            ?? throw DomainException::notFound('Unidade de medida não encontrada.');
    }

    /**
     * Valida uma referência a unidade vinda de outro cadastro (422 no campo).
     */
    public function getForTenant(Uuid $tenantId, Uuid $id, string $field = 'unitId'): UnitOfMeasure
    {
        return $this->repository->findForTenant(UnitOfMeasure::class, $tenantId, $id)
            ?? throw DomainException::invalidField($field, 'Unidade de medida não encontrada.');
    }

    public function create(string $code, string $name, Dimension $dimension, string $baseFactor): UnitOfMeasure
    {
        $tenantId = $this->context->getTenantId();
        $this->assertFactor($baseFactor);
        $this->assertUniqueCode($tenantId, $code);

        $unit = new UnitOfMeasure($tenantId, $code, $name, $dimension, $baseFactor);
        $this->repository->add($unit);
        $this->repository->flush();

        return $unit;
    }

    /**
     * Nome pode mudar sempre. Código, dimensão e fator só enquanto a unidade
     * não for usada: alterá-los reinterpretaria quantidades já cadastradas.
     */
    public function update(Uuid $id, string $code, string $name, Dimension $dimension, string $baseFactor): UnitOfMeasure
    {
        $unit = $this->get($id);
        $this->assertFactor($baseFactor);

        $structuralChange = UnitOfMeasure::normalizeCode($code) !== $unit->getCode()
            || $dimension !== $unit->getDimension()
            || 0 !== Decimal::compare($baseFactor, $unit->getBaseFactor());

        if ($structuralChange) {
            if ($unit->isBase()) {
                throw new DomainException('A unidade-base da dimensão não pode ser redefinida.', 'BASE_UNIT_LOCKED');
            }
            if ($this->repository->isUnitInUse($unit->getTenantId(), $unit->getId())) {
                throw new DomainException('Esta unidade já é usada por produtos ou ingredientes; só o nome pode ser alterado.', 'UNIT_IN_USE');
            }
            $this->assertUniqueCode($unit->getTenantId(), $code, $unit->getId());
            $unit->redefine($code, $dimension, $baseFactor);
        }

        $unit->rename($name);
        $this->repository->flush();

        return $unit;
    }

    public function delete(Uuid $id): void
    {
        $unit = $this->get($id);
        if ($unit->isBase()) {
            throw new DomainException('A unidade-base da dimensão não pode ser excluída.', 'BASE_UNIT_LOCKED');
        }
        if ($this->repository->isUnitInUse($unit->getTenantId(), $unit->getId())) {
            throw new DomainException('Esta unidade está em uso e não pode ser excluída.', 'UNIT_IN_USE');
        }

        $this->repository->remove($unit);
        $this->repository->flush();
    }

    /**
     * @return array{from: UnitOfMeasure, to: UnitOfMeasure, result: string}
     */
    public function convert(string $quantity, Uuid $fromId, Uuid $toId): array
    {
        $tenantId = $this->context->getTenantId();
        $from = $this->getForTenant($tenantId, $fromId, 'from');
        $to = $this->getForTenant($tenantId, $toId, 'to');

        return ['from' => $from, 'to' => $to, 'result' => $this->converter->convert($quantity, $from, $to)];
    }

    private function assertFactor(string $baseFactor): void
    {
        Decimal::assertValid($baseFactor, 'baseFactor');
        if (Decimal::compare($baseFactor, '0') <= 0) {
            throw DomainException::invalidField('baseFactor', 'O fator de conversão deve ser maior que zero.');
        }
    }

    private function assertUniqueCode(Uuid $tenantId, string $code, ?Uuid $exceptId = null): void
    {
        if ($this->repository->existsForTenant(UnitOfMeasure::class, $tenantId, ['code' => UnitOfMeasure::normalizeCode($code)], $exceptId)) {
            throw DomainException::conflict('Já existe uma unidade com este código.', 'DUPLICATE_UNIT');
        }
    }
}
