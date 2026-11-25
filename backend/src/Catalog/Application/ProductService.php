<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\Product;
use App\Catalog\Domain\Model\ProductData;
use App\Catalog\Domain\ProductionType;
use App\Catalog\Domain\StockItemType;
use App\Catalog\Infrastructure\CatalogRepository;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Symfony\Component\Uid\Uuid;

final class ProductService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CatalogRepository $repository,
        private readonly UnitService $units,
        private readonly StockUsageChecker $stockUsage,
        private readonly RecipeUsageChecker $recipeUsage,
    ) {
    }

    /**
     * @param array{q?: ?string, active?: ?bool, categoryId?: ?Uuid, productionType?: ?string} $filters
     *
     * @return array{items: list<Product>, total: int}
     */
    public function search(array $filters, int $offset, int $limit): array
    {
        return $this->repository->searchProducts($this->context->getTenantId(), $filters, $offset, $limit);
    }

    public function get(Uuid $id): Product
    {
        // 404 também para IDs de outro tenant: não revela que existem.
        return $this->repository->findForTenant(Product::class, $this->context->getTenantId(), $id)
            ?? throw DomainException::notFound('Produto não encontrado.');
    }

    public function create(ProductData $data): Product
    {
        $tenantId = $this->context->getTenantId();
        $this->validate($tenantId, $data);

        $product = new Product($tenantId, $data);
        $this->repository->add($product);
        $this->repository->flush();

        return $product;
    }

    public function update(Uuid $id, ProductData $data): Product
    {
        $product = $this->get($id);
        $this->validate($product->getTenantId(), $data, $product->getId());
        $this->assertStockCompatible($product, $data);

        $product->update($data);
        $this->repository->flush();

        return $product;
    }

    /**
     * A unidade de venda é a unidade de estoque do produto: com movimentações
     * lançadas ela não pode mudar (o ledger seria reinterpretado).
     */
    private function assertStockCompatible(Product $product, ProductData $data): void
    {
        $tenantId = $product->getTenantId();
        if (!$data->saleUnitId->equals($product->getSaleUnitId())
            && $this->stockUsage->hasMovements($tenantId, StockItemType::PRODUCT, $product->getId())) {
            throw DomainException::invalidField('saleUnitId', 'Este produto já tem movimentações de estoque; a unidade não pode mudar.');
        }

        if ($this->recipeUsage->productHasOpenRecipe($tenantId, $product->getId())) {
            if (ProductionType::MANUFACTURED !== $data->productionType) {
                throw DomainException::invalidField('productionType', 'Este produto tem ficha técnica ativa ou em rascunho; ele precisa continuar como fabricado aqui.');
            }
            $newUnit = $this->units->getForTenant($tenantId, $data->saleUnitId, 'saleUnitId');
            $oldUnit = $this->units->getForTenant($tenantId, $product->getSaleUnitId(), 'saleUnitId');
            if ($newUnit->getDimension() !== $oldUnit->getDimension()) {
                throw DomainException::invalidField('saleUnitId', 'Este produto tem ficha técnica; a unidade de venda não pode mudar de dimensão (o rendimento da receita deixaria de ser convertível).');
            }
        }

        $willTrack = ProductionType::SERVICE !== $data->productionType && $data->stockTracked;
        if ($product->isStockTracked() && !$willTrack
            && $this->stockUsage->hasStock($tenantId, StockItemType::PRODUCT, $product->getId())) {
            throw DomainException::invalidField('stockTracked', 'Zere o estoque deste produto antes de desligar o controle de estoque.');
        }
    }

    private function validate(Uuid $tenantId, ProductData $data, ?Uuid $exceptId = null): void
    {
        if (null !== $data->categoryId) {
            $category = $this->repository->findForTenant(Category::class, $tenantId, $data->categoryId)
                ?? throw DomainException::invalidField('categoryId', 'Categoria não encontrada.');
            if (!$category->getKind()->acceptsProducts()) {
                throw DomainException::invalidField('categoryId', 'Esta categoria é exclusiva de ingredientes.');
            }
        }

        $this->units->getForTenant($tenantId, $data->saleUnitId, 'saleUnitId');

        if (null !== $data->sku && $this->repository->existsForTenant(Product::class, $tenantId, ['sku' => $data->sku], $exceptId)) {
            throw DomainException::conflict('Já existe um produto com este código (SKU).', 'DUPLICATE_SKU');
        }
        if (null !== $data->barcode && $this->repository->existsForTenant(Product::class, $tenantId, ['barcode' => $data->barcode], $exceptId)) {
            throw DomainException::conflict('Já existe um produto com este código de barras.', 'DUPLICATE_BARCODE');
        }
    }
}
