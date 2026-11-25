<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Request;

use App\Catalog\Domain\Model\ProductData;
use App\Catalog\Domain\ProductionType;
use App\Shared\Domain\Decimal;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final class ProductRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o nome.')]
        #[Assert\Length(max: 160)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Selecione a unidade de venda.')]
        #[Assert\Uuid(message: 'Unidade inválida.')]
        public readonly string $saleUnitId = '',

        #[Assert\NotBlank(message: 'Selecione o tipo de produto.')]
        #[Assert\Choice(choices: ['MANUFACTURED', 'RESALE', 'SERVICE'], message: 'Tipo inválido.')]
        public readonly string $productionType = '',

        #[Assert\NotBlank(message: 'Informe o preço de venda.')]
        #[Assert\Regex(pattern: '/^\d{1,12}(\.\d{1,2})?$/', message: 'Informe um preço válido (ex.: 12.50).')]
        public readonly string $salePrice = '',

        #[Assert\Uuid(message: 'Categoria inválida.')]
        public readonly ?string $categoryId = null,

        #[Assert\Length(max: 64)]
        public readonly ?string $sku = null,

        #[Assert\Length(max: 64)]
        public readonly ?string $barcode = null,

        #[Assert\Length(max: 2000)]
        public readonly ?string $description = null,

        public readonly bool $stockTracked = true,

        public readonly bool $active = true,
    ) {
    }

    public function toData(): ProductData
    {
        return new ProductData(
            name: $this->name,
            saleUnitId: Uuid::fromString($this->saleUnitId),
            productionType: ProductionType::from($this->productionType),
            salePrice: Decimal::round($this->salePrice, 2),
            categoryId: self::uuidOrNull($this->categoryId),
            sku: self::blankToNull($this->sku),
            barcode: self::blankToNull($this->barcode),
            description: self::blankToNull($this->description),
            stockTracked: $this->stockTracked,
            active: $this->active,
        );
    }

    public static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    public static function uuidOrNull(?string $value): ?Uuid
    {
        return null === $value || '' === $value ? null : Uuid::fromString($value);
    }
}
