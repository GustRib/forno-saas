<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Request;

use App\Catalog\Domain\Model\IngredientData;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final class IngredientRequest
{
    private const QTY = '/^\d{1,12}(\.\d{1,6})?$/';

    public function __construct(
        #[Assert\NotBlank(message: 'Informe o nome.')]
        #[Assert\Length(max: 160)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Selecione a unidade de estoque.')]
        #[Assert\Uuid(message: 'Unidade inválida.')]
        public readonly string $stockUnitId = '',

        #[Assert\NotBlank(message: 'Informe o custo por unidade de estoque.')]
        #[Assert\Regex(pattern: self::QTY, message: 'Informe um custo válido (até 6 casas decimais).')]
        public readonly string $averageUnitCost = '0',

        #[Assert\NotBlank(message: 'Informe o estoque mínimo.')]
        #[Assert\Regex(pattern: self::QTY, message: 'Informe uma quantidade válida (até 6 casas decimais).')]
        public readonly string $minimumStock = '0',

        #[Assert\Uuid(message: 'Categoria inválida.')]
        public readonly ?string $categoryId = null,

        #[Assert\Length(max: 64)]
        public readonly ?string $sku = null,

        #[Assert\Uuid(message: 'Unidade inválida.')]
        public readonly ?string $purchaseUnitId = null,

        #[Assert\Regex(pattern: self::QTY, message: 'Informe um fator válido (até 6 casas decimais).')]
        public readonly ?string $purchaseToStockFactor = null,

        public readonly bool $active = true,
    ) {
    }

    public function toData(): IngredientData
    {
        return new IngredientData(
            name: $this->name,
            stockUnitId: Uuid::fromString($this->stockUnitId),
            averageUnitCost: $this->averageUnitCost,
            minimumStock: $this->minimumStock,
            categoryId: ProductRequest::uuidOrNull($this->categoryId),
            sku: ProductRequest::blankToNull($this->sku),
            purchaseUnitId: ProductRequest::uuidOrNull($this->purchaseUnitId),
            purchaseToStockFactor: ProductRequest::blankToNull($this->purchaseToStockFactor),
            active: $this->active,
        );
    }
}
