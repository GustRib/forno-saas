<?php

declare(strict_types=1);

namespace App\Recipe\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Registro imutável do custo calculado de uma versão (FNO-044 "histórico de
 * custo"): o custo de cada ingrediente, a data e a origem usados (FR-025), e a
 * política de custo em vigor. O banco recusa UPDATE/DELETE.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'recipe_cost_snapshots')]
class RecipeCostSnapshot
{
    public const REASON_ACTIVATION = 'ACTIVATION';
    public const REASON_MANUAL = 'MANUAL';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $recipeId;

    #[ORM\Column(length: 20)]
    private string $reason;

    #[ORM\Column(length: 20)]
    private string $costPolicy;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $ingredientsCost;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $additionalCost;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $totalCost;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $costPerYieldUnit;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6, nullable: true)]
    private ?string $costPerSaleUnit;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $yieldQty;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $yieldUnitId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $details;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        Recipe $recipe,
        string $reason,
        string $costPolicy,
        string $ingredientsCost,
        string $additionalCost,
        string $totalCost,
        string $costPerYieldUnit,
        ?string $costPerSaleUnit,
        array $details,
        Uuid $createdBy,
    ) {
        $this->id = Uuid::v7();
        $this->tenantId = $recipe->getTenantId();
        $this->recipeId = $recipe->getId();
        $this->reason = $reason;
        $this->costPolicy = $costPolicy;
        $this->ingredientsCost = $ingredientsCost;
        $this->additionalCost = $additionalCost;
        $this->totalCost = $totalCost;
        $this->costPerYieldUnit = $costPerYieldUnit;
        $this->costPerSaleUnit = $costPerSaleUnit;
        $this->yieldQty = $recipe->getExpectedYieldQty();
        $this->yieldUnitId = $recipe->getYieldUnitId();
        $this->details = $details;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipeId(): Uuid
    {
        return $this->recipeId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCostPolicy(): string
    {
        return $this->costPolicy;
    }

    public function getIngredientsCost(): string
    {
        return $this->ingredientsCost;
    }

    public function getAdditionalCost(): string
    {
        return $this->additionalCost;
    }

    public function getTotalCost(): string
    {
        return $this->totalCost;
    }

    public function getCostPerYieldUnit(): string
    {
        return $this->costPerYieldUnit;
    }

    public function getCostPerSaleUnit(): ?string
    {
        return $this->costPerSaleUnit;
    }

    public function getYieldQty(): string
    {
        return $this->yieldQty;
    }

    public function getYieldUnitId(): Uuid
    {
        return $this->yieldUnitId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
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
