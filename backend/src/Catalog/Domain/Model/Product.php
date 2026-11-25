<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\ProductionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Item comercializado (fabricado, revenda ou serviço).
 *
 * Referências a outras entidades do tenant são guardadas como UUID e
 * validadas no caso de uso; no banco, FKs compostas (tenant_id, id)
 * impedem referências cruzadas entre tenants.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product
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

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $saleUnitId;

    #[ORM\Column]
    private bool $stockTracked = true;

    #[ORM\Column(length: 20, enumType: ProductionType::class)]
    private ProductionType $productionType;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $salePrice = '0.00';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $tenantId, ProductData $data)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->apply($data);
    }

    public function update(ProductData $data): void
    {
        $this->apply($data);
    }

    private function apply(ProductData $data): void
    {
        $this->categoryId = $data->categoryId;
        $this->name = trim($data->name);
        $this->sku = $data->sku;
        $this->barcode = $data->barcode;
        $this->description = $data->description;
        $this->saleUnitId = $data->saleUnitId;
        $this->productionType = $data->productionType;
        // Serviço nunca controla estoque.
        $this->stockTracked = ProductionType::SERVICE !== $data->productionType && $data->stockTracked;
        $this->salePrice = $data->salePrice;
        $this->active = $data->active;
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

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getSaleUnitId(): Uuid
    {
        return $this->saleUnitId;
    }

    public function isStockTracked(): bool
    {
        return $this->stockTracked;
    }

    public function getProductionType(): ProductionType
    {
        return $this->productionType;
    }

    public function getSalePrice(): string
    {
        return $this->salePrice;
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
