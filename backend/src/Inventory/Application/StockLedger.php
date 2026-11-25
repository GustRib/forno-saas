<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Catalog\Application\IngredientCostLedger;
use App\Catalog\Application\StockItemCatalog;
use App\Catalog\Application\StockItemView;
use App\Catalog\Application\UnitService;
use App\Catalog\Domain\StockItemType;
use App\Catalog\Domain\UnitConverter;
use App\Inventory\Domain\Model\StockLocation;
use App\Inventory\Domain\Model\StockMovement;
use App\Inventory\Domain\MovementType;
use App\Inventory\Domain\WeightedAverageCost;
use App\Inventory\Infrastructure\StockRepository;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lançamentos manuais no ledger de estoque (FNO-030, FNO-031, FNO-033).
 *
 * Cada lançamento acontece numa transação que:
 *  1. bloqueia a linha de saldo do item/local (serializa concorrência);
 *  2. valida a regra de negócio contra o saldo bloqueado;
 *  3. insere o movimento — o trigger do banco atualiza o saldo.
 * Se algo falhar, nada é gravado. O banco ainda recusa saldo negativo,
 * movimento alterado/apagado e estorno duplicado (DECISIONS D-13).
 */
final class StockLedger
{
    /** Tolerância para datas informadas "no futuro" (relógios dessincronizados). */
    private const FUTURE_TOLERANCE = '+5 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $context,
        private readonly StockRepository $repository,
        private readonly StockItemCatalog $catalog,
        private readonly UnitService $units,
        private readonly UnitConverter $converter,
        private readonly IngredientCostLedger $costs,
    ) {
    }

    /**
     * FNO-031: saldo inicial. Só é permitido enquanto o item não tem nenhuma
     * movimentação no local; depois disso, correções são ajustes.
     */
    public function recordOpeningBalance(MovementInput $input): StockMovement
    {
        return $this->transactional(function () use ($input): StockMovement {
            $tenantId = $this->context->getTenantId();
            [$item, $location] = $this->resolveTarget($tenantId, $input);
            $quantity = $this->toStockQuantity($tenantId, $input, $item);

            $average = $this->lockItemCost($tenantId, $item);
            $this->repository->lockBalance($tenantId, $item->type, $item->id, $location->getId(), $item->stockUnit->getId());
            if ($this->repository->hasMovements($tenantId, $item->type, $item->id, $location->getId())) {
                throw DomainException::conflict('Este item já tem movimentações neste local. Para corrigir a quantidade, use um ajuste.', 'OPENING_BALANCE_NOT_ALLOWED');
            }

            return $this->post($tenantId, $item, $location, MovementType::OPENING_BALANCE, $quantity, $input, $average);
        });
    }

    /**
     * FNO-033 / FR-035: perda/descarte, devolução e ajustes, sempre com motivo.
     */
    public function recordAdjustment(MovementType $type, MovementInput $input): StockMovement
    {
        if (!\in_array($type, MovementType::ADJUSTMENT_TYPES, true)) {
            throw DomainException::invalidField('type', 'Tipo de lançamento inválido.');
        }

        return $this->transactional(function () use ($type, $input): StockMovement {
            $tenantId = $this->context->getTenantId();
            [$item, $location] = $this->resolveTarget($tenantId, $input);
            $quantity = $this->toStockQuantity($tenantId, $input, $item);

            $average = $this->lockItemCost($tenantId, $item);
            $balance = $this->repository->lockBalance($tenantId, $item->type, $item->id, $location->getId(), $item->stockUnit->getId());
            $delta = $type->isInbound() ? $quantity : '-'.$quantity;
            $this->assertNotNegative($balance, $delta, $item);

            return $this->post($tenantId, $item, $location, $type, $delta, $input, $average);
        });
    }

    /**
     * Estorno: novo movimento que espelha o original com sinal invertido
     * (regra §5.5). Um movimento só pode ser estornado uma vez.
     */
    public function reverse(Uuid $movementId, string $reason): StockMovement
    {
        return $this->transactional(function () use ($movementId, $reason): StockMovement {
            $tenantId = $this->context->getTenantId();
            $original = $this->repository->findMovement($tenantId, $movementId)
                ?? throw DomainException::notFound('Movimentação não encontrada.');

            if (!$original->getMovementType()->isManuallyReversible()) {
                throw new DomainException(MovementType::REVERSAL === $original->getMovementType() ? 'Um estorno não pode ser estornado. Lance um novo ajuste se precisar.' : 'Esta movimentação deve ser cancelada no módulo de origem.', 'NOT_REVERSIBLE');
            }
            $this->assertReason($reason);

            // Auditoria do épico 3: não devolver estoque a produto que deixou de controlá-lo.
            $item = $this->catalog->find($tenantId, $original->getItemType(), $original->getItemId())
                ?? throw DomainException::notFound('Item não encontrado.');
            if (!$item->stockTracked) {
                throw new DomainException('Este produto não controla mais estoque; o lançamento não pode ser estornado.', 'NOT_STOCK_TRACKED');
            }

            $average = $this->lockItemCost($tenantId, $item);
            $balance = $this->repository->lockBalance(
                $tenantId,
                $original->getItemType(),
                $original->getItemId(),
                $original->getStockLocationId(),
                $original->getUnitId(),
            );
            // Verificado após o bloqueio: dois estornos simultâneos não passam.
            if ($this->repository->isReversed($tenantId, $original->getId())) {
                throw DomainException::conflict('Esta movimentação já foi estornada.', 'ALREADY_REVERSED');
            }

            $delta = $this->negate($original->getQuantityDelta());
            if (Decimal::compare(bcadd($balance, $delta, 6), '0') < 0) {
                throw new DomainException('Estornar este lançamento deixaria o saldo negativo. Registre as entradas que faltam antes.', 'INSUFFICIENT_STOCK');
            }

            $reversal = new StockMovement(
                tenantId: $tenantId,
                itemType: $original->getItemType(),
                itemId: $original->getItemId(),
                stockLocationId: $original->getStockLocationId(),
                movementType: MovementType::REVERSAL,
                quantityDelta: $delta,
                unitId: $original->getUnitId(),
                createdBy: $this->context->getUser()->getId(),
                occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                reason: $reason,
                reversalOfId: $original->getId(),
            );

            // WAC-1: estornar uma entrada com custo tira do estoque o valor que ela trouxe.
            $newAverage = null;
            if (null !== $average && null !== $original->getUnitCost() && $original->getMovementType()->isInbound()) {
                $newAverage = WeightedAverageCost::afterInboundReversal(
                    $this->repository->totalQuantity($tenantId, $item->type, $item->id),
                    $average,
                    $original->getQuantityDelta(),
                    $original->getUnitCost(),
                );
            }

            $this->repository->addMovement($reversal);
            $this->repository->flush();
            $this->applyCost($tenantId, $item, $newAverage, $reversal);

            return $reversal;
        });
    }

    /**
     * Bloqueia o custo do ingrediente antes do saldo (ordem fixa: ingrediente →
     * saldo, evita deadlock). Todas as movimentações de um ingrediente ficam
     * serializadas, então o saldo total usado no custo médio é consistente.
     * Produtos não têm custo de estoque neste épico: devolve null.
     */
    private function lockItemCost(Uuid $tenantId, StockItemView $item): ?string
    {
        return StockItemType::INGREDIENT === $item->type ? $this->costs->lockCurrentCost($tenantId, $item->id) : null;
    }

    private function applyCost(Uuid $tenantId, StockItemView $item, ?string $newAverage, StockMovement $movement): void
    {
        if (null === $newAverage) {
            return;
        }
        // Flush separado: o histórico referencia o movimento (FK), que precisa existir antes.
        $this->costs->recordMovementCost($tenantId, $item->id, $newAverage, $movement->getId(), $this->context->getUser()->getId());
        $this->repository->flush();
    }

    private function post(Uuid $tenantId, StockItemView $item, StockLocation $location, MovementType $type, string $delta, MovementInput $input, ?string $average): StockMovement
    {
        if ($type->requiresReason()) {
            $this->assertReason((string) $input->reason);
        }

        if (null !== $input->unitCost && !$type->acceptsUnitCost()) {
            throw DomainException::invalidField('unitCost', 'Custo unitário só pode ser informado em entradas.');
        }

        $movement = new StockMovement(
            tenantId: $tenantId,
            itemType: $item->type,
            itemId: $item->id,
            stockLocationId: $location->getId(),
            movementType: $type,
            quantityDelta: $delta,
            unitId: $item->stockUnit->getId(),
            createdBy: $this->context->getUser()->getId(),
            occurredAt: $this->occurredAt($input->occurredAt),
            reason: null !== $input->reason && '' !== trim($input->reason) ? $input->reason : null,
            unitCost: $input->unitCost,
        );

        // WAC-1: só entrada com custo informado muda o custo médio do ingrediente.
        $newAverage = null;
        if (null !== $average && null !== $input->unitCost && $type->isInbound()) {
            $newAverage = WeightedAverageCost::afterInbound(
                $this->repository->totalQuantity($tenantId, $item->type, $item->id),
                $average,
                $delta,
                $input->unitCost,
            );
        }

        $this->repository->addMovement($movement);
        $this->repository->flush();
        $this->applyCost($tenantId, $item, $newAverage, $movement);

        return $movement;
    }

    /**
     * @return array{StockItemView, StockLocation}
     */
    private function resolveTarget(Uuid $tenantId, MovementInput $input): array
    {
        $item = $this->catalog->find($tenantId, $input->itemType, $input->itemId)
            ?? throw DomainException::invalidField('itemId', 'Item não encontrado.');

        if (!$item->stockTracked) {
            throw DomainException::invalidField('itemId', 'Este produto não controla estoque.');
        }

        return [$item, $this->resolveLocation($tenantId, $input->locationId)];
    }

    private function resolveLocation(Uuid $tenantId, ?Uuid $locationId): StockLocation
    {
        if (null !== $locationId) {
            return $this->repository->findLocation($tenantId, $locationId)
                ?? throw DomainException::invalidField('locationId', 'Local de estoque não encontrado.');
        }

        $default = $this->repository->defaultLocation($tenantId);
        if (null === $default) {
            // Autocorreção: todo tenant deve ter um local padrão (criado com o tenant).
            $default = new StockLocation($tenantId, StockLocation::DEFAULT_NAME, true);
            $this->repository->addLocation($default);
            $this->repository->flush();
        }

        return $default;
    }

    /**
     * Converte a quantidade informada para a unidade de estoque do item, sem
     * arredondar. Unidades de outra dimensão são recusadas.
     */
    private function toStockQuantity(Uuid $tenantId, MovementInput $input, StockItemView $item): string
    {
        Decimal::assertValid($input->quantity, 'quantity');
        if (Decimal::compare($input->quantity, '0') <= 0) {
            throw DomainException::invalidField('quantity', 'Informe uma quantidade maior que zero.');
        }

        $from = null === $input->unitId ? $item->stockUnit : $this->units->getForTenant($tenantId, $input->unitId, 'unitId');
        $quantity = $this->converter->convertExact($input->quantity, $from, $item->stockUnit);

        if (0 === Decimal::compare($quantity, '0')) {
            throw DomainException::invalidField('quantity', 'A quantidade é pequena demais para a unidade de estoque.');
        }

        return $quantity;
    }

    private function assertNotNegative(string $balance, string $delta, StockItemView $item): void
    {
        if (Decimal::compare(bcadd($balance, $delta, 6), '0') < 0) {
            throw new DomainException(\sprintf('Estoque insuficiente: há %s %s disponível.', $this->ptBr($this->trim($balance)), $item->stockUnit->getCode()), 'INSUFFICIENT_STOCK', 422, ['available' => $this->trim($balance), 'unit' => $item->stockUnit->getCode()]);
        }
    }

    /**
     * Só para mensagens: "25500.5" → "25.500,5" (sem passar por float).
     */
    private function ptBr(string $decimal): string
    {
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $grouped = strrev(implode('.', str_split(strrev($int), 3)));

        return '' === $frac ? $grouped : $grouped.','.$frac;
    }

    private function assertReason(string $reason): void
    {
        if ('' === trim($reason)) {
            throw DomainException::invalidField('reason', 'Informe o motivo.');
        }
        if (mb_strlen(trim($reason)) > 255) {
            throw DomainException::invalidField('reason', 'O motivo deve ter no máximo 255 caracteres.');
        }
    }

    private function occurredAt(?\DateTimeImmutable $occurredAt): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (null === $occurredAt) {
            return $now;
        }
        if ($occurredAt > $now->modify(self::FUTURE_TOLERANCE)) {
            throw DomainException::invalidField('occurredAt', 'A data do lançamento não pode estar no futuro.');
        }

        return $occurredAt;
    }

    private function negate(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : '-'.$value;
    }

    private function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        try {
            return $this->em->wrapInTransaction($operation);
        } catch (DriverException $e) {
            // Invariantes do banco: só chegam aqui se a validação acima foi
            // contornada (ex.: corrida não coberta). Mensagem amigável ao usuário.
            throw match ($e->getSQLState()) {
                '23514' => new DomainException('Estoque insuficiente para este lançamento.', 'INSUFFICIENT_STOCK'),
                '23505' => DomainException::conflict('Este lançamento conflita com outro já registrado. Recarregue e tente novamente.', 'STOCK_CONFLICT'),
                '22003' => DomainException::invalidField('quantity', 'Quantidade grande demais.'),
                default => $e,
            };
        }
    }
}
