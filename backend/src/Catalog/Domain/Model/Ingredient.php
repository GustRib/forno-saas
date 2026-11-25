<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Insumo usado em receitas. O estoque é controlado na unidade-base
 * `stockUnitId`; a unidade de compra é convertida por `purchaseToStockFactor`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ingredients')]
class Ingredient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $categoryId = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $stockUnitId;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $purchaseUnitId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6, nullable: true)]
    private ?string $purchaseToStockFactor = null;

    /**
     * Custo por unidade de estoque. Valor de apoio: a política de custo
     * (médio ponderado × último custo) ainda está em aberto (REQUIREMENTS §7)
     * e será aplicada pelos recebimentos de compra.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $averageUnitCost = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $minimumStock = '0';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $tenantId, IngredientData $data)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->apply($data);
    }

    public function update(IngredientData $data): void
    {
        $this->apply($data);
    }

    private function apply(IngredientData $data): void
    {
        $this->categoryId = $data->categoryId;
        $this->name = trim($data->name);
        $this->sku = $data->sku;
        $this->stockUnitId = $data->stockUnitId;
        $this->purchaseUnitId = $data->purchaseUnitId;
        $this->purchaseToStockFactor = $data->purchaseToStockFactor;
        $this->averageUnitCost = $data->averageUnitCost;
        $this->minimumStock = $data->minimumStock;
        $this->active = $data->active;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Novo custo médio calculado pela política de custo (ver IngredientCostLedger).
     */
    public function changeAverageCost(string $cost): void
    {
        $this->averageUnitCost = $cost;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getCategoryId(): ?Uuid
    {
        return $this->categoryId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getStockUnitId(): Uuid
    {
        return $this->stockUnitId;
    }

    public function getPurchaseUnitId(): ?Uuid
    {
        return $this->purchaseUnitId;
    }

    public function getPurchaseToStockFactor(): ?string
    {
        return $this->purchaseToStockFactor;
    }

    public function getAverageUnitCost(): string
    {
        return $this->averageUnitCost;
    }

    public function getMinimumStock(): string
    {
        return $this->minimumStock;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
