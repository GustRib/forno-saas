<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\Category;
use App\Catalog\Domain\Model\Ingredient;
use App\Catalog\Domain\Model\IngredientData;
use App\Catalog\Domain\StockItemType;
use App\Catalog\Domain\UnitConverter;
use App\Catalog\Infrastructure\CatalogRepository;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class IngredientService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CatalogRepository $repository,
        private readonly UnitService $units,
        private readonly UnitConverter $converter,
        private readonly StockUsageChecker $stockUsage,
        private readonly RecipeUsageChecker $recipeUsage,
        private readonly IngredientCostLedger $costs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array{q?: ?string, active?: ?bool, categoryId?: ?Uuid} $filters
     *
     * @return array{items: list<Ingredient>, total: int}
     */
    public function search(array $filters, int $offset, int $limit): array
    {
        return $this->repository->searchIngredients($this->context->getTenantId(), $filters, $offset, $limit);
    }

    public function get(Uuid $id): Ingredient
    {
        return $this->repository->findForTenant(Ingredient::class, $this->context->getTenantId(), $id)
            ?? throw DomainException::notFound('Ingrediente não encontrado.');
    }

    public function create(IngredientData $data): Ingredient
    {
        $tenantId = $this->context->getTenantId();
        $data = $this->validate($tenantId, $data);

        $ingredient = new Ingredient($tenantId, $data);
        $this->repository->add($ingredient);
        // FR-025: o custo informado no cadastro é a primeira origem do custo.
        $this->costs->recordManualCost($ingredient, null, $this->context->getUser()->getId());
        $this->repository->flush();

        return $ingredient;
    }

    public function update(Uuid $id, IngredientData $data): Ingredient
    {
        return $this->em->wrapInTransaction(function () use ($id, $data): Ingredient {
            $ingredient = $this->get($id);
            // Mesmo bloqueio usado pelo estoque ao recalcular o custo médio:
            // edição manual e entradas com custo não se atropelam.
            $previousCost = $this->costs->lockCurrentCost($ingredient->getTenantId(), $ingredient->getId());
            $data = $this->validate($ingredient->getTenantId(), $data, $ingredient->getId());

            if (!$data->stockUnitId->equals($ingredient->getStockUnitId())) {
                if ($this->stockUsage->hasMovements($ingredient->getTenantId(), StockItemType::INGREDIENT, $ingredient->getId())) {
                    throw DomainException::invalidField('stockUnitId', 'Este ingrediente já tem movimentações de estoque; a unidade de estoque não pode mudar.');
                }
                $newUnit = $this->units->getForTenant($ingredient->getTenantId(), $data->stockUnitId, 'stockUnitId');
                $oldUnit = $this->units->getForTenant($ingredient->getTenantId(), $ingredient->getStockUnitId(), 'stockUnitId');
                if ($newUnit->getDimension() !== $oldUnit->getDimension()
                    && $this->recipeUsage->isIngredientInOpenRecipe($ingredient->getTenantId(), $ingredient->getId())) {
                    throw DomainException::invalidField('stockUnitId', 'Este ingrediente está em uma receita ativa ou em rascunho; a unidade de estoque não pode mudar de dimensão.');
                }
            }

            $ingredient->update($data);
            $this->costs->recordManualCost($ingredient, $previousCost, $this->context->getUser()->getId());
            $this->repository->flush();

            return $ingredient;
        });
    }

    /**
     * Valida referências e completa o fator de compra quando possível.
     */
    private function validate(Uuid $tenantId, IngredientData $data, ?Uuid $exceptId = null): IngredientData
    {
        if (null !== $data->categoryId) {
            $category = $this->repository->findForTenant(Category::class, $tenantId, $data->categoryId)
                ?? throw DomainException::invalidField('categoryId', 'Categoria não encontrada.');
            if (!$category->getKind()->acceptsIngredients()) {
                throw DomainException::invalidField('categoryId', 'Esta categoria é exclusiva de produtos de venda.');
            }
        }

        $stockUnit = $this->units->getForTenant($tenantId, $data->stockUnitId, 'stockUnitId');

        $factor = $data->purchaseToStockFactor;
        if (null !== $data->purchaseUnitId) {
            $purchaseUnit = $this->units->getForTenant($tenantId, $data->purchaseUnitId, 'purchaseUnitId');
            if (null === $factor) {
                if ($purchaseUnit->getDimension() !== $stockUnit->getDimension()) {
                    // Ex.: "saco" (contagem) → gramas: o usuário precisa dizer quanto pesa.
                    throw DomainException::invalidField('purchaseToStockFactor', 'Informe quantas unidades de estoque há em cada unidade de compra.');
                }
                $factor = $this->converter->factor($purchaseUnit, $stockUnit);
            }
        } elseif (null !== $factor) {
            throw DomainException::invalidField('purchaseUnitId', 'Selecione a unidade de compra para usar um fator de conversão.');
        }

        if (null !== $factor && Decimal::compare($factor, '0') <= 0) {
            throw DomainException::invalidField('purchaseToStockFactor', 'O fator de conversão deve ser maior que zero.');
        }

        if (null !== $data->sku && $this->repository->existsForTenant(Ingredient::class, $tenantId, ['sku' => $data->sku], $exceptId)) {
            throw DomainException::conflict('Já existe um ingrediente com este código (SKU).', 'DUPLICATE_SKU');
        }

        return new IngredientData(
            name: $data->name,
            stockUnitId: $data->stockUnitId,
            averageUnitCost: $data->averageUnitCost,
            minimumStock: $data->minimumStock,
            categoryId: $data->categoryId,
            sku: $data->sku,
            purchaseUnitId: $data->purchaseUnitId,
            purchaseToStockFactor: $factor,
            active: $data->active,
        );
    }
}
