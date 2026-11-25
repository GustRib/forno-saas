<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Model;

use App\Catalog\Domain\StockItemType;
use App\Inventory\Domain\MovementType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Linha do ledger de estoque (FR-031). Imutável: não há setters, e o banco
 * recusa UPDATE/DELETE. Correções são novos movimentos (estornos).
 *
 * `quantityDelta` está sempre na unidade de estoque do item (`unitId`):
 * positivo entra, negativo sai.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'stock_movements')]
class StockMovement
{
    public const SOURCE_MANUAL = 'MANUAL';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $ingredientId;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $productId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $stockLocationId;

    #[ORM\Column(length: 32, enumType: MovementType::class)]
    private MovementType $movementType;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $quantityDelta;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $unitId;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6, nullable: true)]
    private ?string $unitCost;

    #[ORM\Column(length: 40)]
    private string $sourceType;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $sourceId;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $reversalOfId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $tenantId,
        StockItemType $itemType,
        Uuid $itemId,
        Uuid $stockLocationId,
        MovementType $movementType,
        string $quantityDelta,
        Uuid $unitId,
        Uuid $createdBy,
        \DateTimeImmutable $occurredAt,
        ?string $reason = null,
        ?string $unitCost = null,
        ?Uuid $reversalOfId = null,
        string $sourceType = self::SOURCE_MANUAL,
        ?Uuid $sourceId = null,
    ) {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->ingredientId = StockItemType::INGREDIENT === $itemType ? $itemId : null;
        $this->productId = StockItemType::PRODUCT === $itemType ? $itemId : null;
        $this->stockLocationId = $stockLocationId;
        $this->movementType = $movementType;
        $this->quantityDelta = $quantityDelta;
        $this->unitId = $unitId;
        $this->unitCost = $unitCost;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->reversalOfId = $reversalOfId;
        $this->reason = null === $reason ? null : trim($reason);
        $this->occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getItemType(): StockItemType
    {
        return null !== $this->ingredientId ? StockItemType::INGREDIENT : StockItemType::PRODUCT;
    }

    public function getItemId(): Uuid
    {
        return $this->ingredientId ?? $this->productId ?? throw new \LogicException('Movimento sem item.');
    }

    public function getStockLocationId(): Uuid
    {
        return $this->stockLocationId;
    }

    public function getMovementType(): MovementType
    {
        return $this->movementType;
    }

    public function getQuantityDelta(): string
    {
        return $this->quantityDelta;
    }

    public function getUnitId(): Uuid
    {
        return $this->unitId;
    }

    public function getUnitCost(): ?string
    {
        return $this->unitCost;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?Uuid
    {
        return $this->sourceId;
    }

    public function getReversalOfId(): ?Uuid
    {
        return $this->reversalOfId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedBy(): Uuid
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
