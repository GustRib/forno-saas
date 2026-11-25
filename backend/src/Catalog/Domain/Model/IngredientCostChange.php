<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\CostSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Linha do histórico de custo de um ingrediente (FNO-044, FR-025).
 * Append-only: o banco recusa UPDATE/DELETE.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'ingredient_cost_history')]
class IngredientCostChange
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $ingredientId;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6, nullable: true)]
    private ?string $previousCost;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $newCost;

    #[ORM\Column(length: 20, enumType: CostSource::class)]
    private CostSource $sourceType;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $stockMovementId;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $costPolicy;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $changedBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(
        Uuid $tenantId,
        Uuid $ingredientId,
        ?string $previousCost,
        string $newCost,
        CostSource $sourceType,
        ?Uuid $changedBy,
        ?Uuid $stockMovementId = null,
        ?string $costPolicy = null,
    ) {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->ingredientId = $ingredientId;
        $this->previousCost = $previousCost;
        $this->newCost = $newCost;
        $this->sourceType = $sourceType;
        $this->stockMovementId = $stockMovementId;
        $this->costPolicy = $costPolicy;
        $this->changedBy = $changedBy;
        $this->changedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIngredientId(): Uuid
    {
        return $this->ingredientId;
    }

    public function getPreviousCost(): ?string
    {
        return $this->previousCost;
    }

    public function getNewCost(): string
    {
        return $this->newCost;
    }

    public function getSourceType(): CostSource
    {
        return $this->sourceType;
    }

    public function getStockMovementId(): ?Uuid
    {
        return $this->stockMovementId;
    }

    public function getCostPolicy(): ?string
    {
        return $this->costPolicy;
    }

    public function getChangedBy(): ?Uuid
    {
        return $this->changedBy;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
