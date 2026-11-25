<?php

declare(strict_types=1);

namespace App\Recipe\Application;

use App\Catalog\Application\RecipeCatalog;
use App\Catalog\Domain\ProductionType;
use App\Recipe\Domain\Model\Recipe;
use App\Recipe\Domain\Model\RecipeContent;
use App\Recipe\Domain\Model\RecipeCostSnapshot;
use App\Recipe\Domain\Model\RecipeItemData;
use App\Recipe\Domain\RecipeStatus;
use App\Recipe\Infrastructure\RecipeRepository;
use App\Shared\Domain\CostPolicy;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Casos de uso das fichas técnicas (FNO-040 a FNO-044).
 *
 * Fluxo de versões (FR-023): cria-se um rascunho (versão n+1), edita-se à
 * vontade, e ao ativar a versão ativa anterior é arquivada — no mesmo
 * momento é gravado o snapshot do custo. Versões publicadas nunca mudam.
 */
final class RecipeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $context,
        private readonly RecipeRepository $repository,
        private readonly RecipeCatalog $catalog,
        private readonly RecipeCostService $costs,
    ) {
    }

    public function get(Uuid $id): Recipe
    {
        return $this->repository->find($this->context->getTenantId(), $id)
            ?? throw DomainException::notFound('Ficha técnica não encontrada.');
    }

    /**
     * FNO-040: ficha técnica vinculada a produto fabricado. Nasce como rascunho.
     */
    public function create(Uuid $productId, RecipeContent $content): Recipe
    {
        return $this->em->wrapInTransaction(function () use ($productId, $content): Recipe {
            $tenantId = $this->context->getTenantId();
            $this->assertManufacturedProduct($tenantId, $productId, 'productId');
            $this->assertNoDraft($tenantId, $productId);
            $content = $this->validate($tenantId, $productId, $content);

            $recipe = new Recipe($tenantId, $productId, $this->repository->nextVersion($tenantId, $productId), $content, $this->context->getUser()->getId());
            $this->repository->add($recipe);
            $this->repository->flush();

            return $recipe;
        });
    }

    public function update(Uuid $id, RecipeContent $content): Recipe
    {
        return $this->em->wrapInTransaction(function () use ($id, $content): Recipe {
            $recipe = $this->get($id);
            if (!$recipe->isDraft()) {
                throw DomainException::conflict('Versões publicadas não podem ser alteradas. Crie uma nova versão.', 'RECIPE_NOT_DRAFT');
            }
            $recipe->replaceContent($this->validate($recipe->getTenantId(), $recipe->getProductId(), $content));
            $this->repository->flush();

            return $recipe;
        });
    }

    /**
     * FNO-043: nova versão (rascunho) a partir de uma versão publicada.
     */
    public function newVersion(Uuid $id): Recipe
    {
        return $this->em->wrapInTransaction(function () use ($id): Recipe {
            $source = $this->get($id);
            if ($source->isDraft()) {
                throw DomainException::conflict('Esta versão ainda é um rascunho; edite-a diretamente.', 'RECIPE_IS_DRAFT');
            }
            $tenantId = $source->getTenantId();
            $this->assertNoDraft($tenantId, $source->getProductId());

            $draft = new Recipe($tenantId, $source->getProductId(), $this->repository->nextVersion($tenantId, $source->getProductId()), $source->content(), $this->context->getUser()->getId());
            $this->repository->add($draft);
            $this->repository->flush();

            return $draft;
        });
    }

    /**
     * Publica o rascunho: arquiva a versão ativa (se houver), ativa esta e
     * grava o snapshot de custo da ativação.
     */
    public function activate(Uuid $id): Recipe
    {
        return $this->em->wrapInTransaction(function () use ($id): Recipe {
            $recipe = $this->get($id);
            if (!$recipe->isDraft()) {
                throw DomainException::conflict('Só um rascunho pode ser ativado.', 'RECIPE_NOT_DRAFT');
            }
            $tenantId = $recipe->getTenantId();
            $this->assertManufacturedProduct($tenantId, $recipe->getProductId(), 'productId');
            // Revalida: o cadastro pode ter mudado desde que o rascunho foi salvo.
            $this->validate($tenantId, $recipe->getProductId(), $recipe->content());

            $cost = $this->costs->calculate($recipe);
            if (!$cost->complete) {
                throw new DomainException('A receita não pode ser ativada: '.implode(' ', array_map(static fn ($w) => $w['message'], array_filter($cost->warnings, static fn ($w) => \in_array($w['code'], ['NO_ITEMS', 'INGREDIENT_MISSING', 'UNIT_INCOMPATIBLE', 'YIELD_UNIT_INCOMPATIBLE'], true)))), 'RECIPE_INCOMPLETE', 422, ['warnings' => $cost->warnings]);
            }

            $current = $this->repository->findByStatus($tenantId, $recipe->getProductId(), RecipeStatus::ACTIVE);
            if (null !== $current) {
                $current->archive();
                // Antes de ativar: o índice único permite uma ativa por produto.
                $this->repository->flush();
            }

            $timezone = new \DateTimeZone($this->context->getTenant()->getTimezone());
            $recipe->activate(new \DateTimeImmutable('today', $timezone));
            $this->repository->flush();

            $this->snapshot($recipe, RecipeCostSnapshot::REASON_ACTIVATION);

            return $recipe;
        });
    }

    public function delete(Uuid $id): void
    {
        $recipe = $this->get($id);
        if (!$recipe->isDraft()) {
            throw DomainException::conflict('Versões publicadas fazem parte do histórico e não podem ser excluídas.', 'RECIPE_NOT_DRAFT');
        }
        $this->repository->remove($recipe);
        $this->repository->flush();
    }

    /**
     * FNO-044: registra o custo atual da versão ativa no histórico.
     */
    public function recordCostSnapshot(Uuid $id): RecipeCostSnapshot
    {
        return $this->em->wrapInTransaction(function () use ($id): RecipeCostSnapshot {
            $recipe = $this->get($id);
            if (RecipeStatus::ACTIVE !== $recipe->getStatus()) {
                throw new DomainException('Só a versão ativa tem o custo registrado no histórico.', 'RECIPE_NOT_ACTIVE');
            }

            return $this->snapshot($recipe, RecipeCostSnapshot::REASON_MANUAL);
        });
    }

    private function snapshot(Recipe $recipe, string $reason): RecipeCostSnapshot
    {
        $cost = $this->costs->calculate($recipe);
        $snapshot = new RecipeCostSnapshot(
            recipe: $recipe,
            reason: $reason,
            costPolicy: CostPolicy::CURRENT,
            ingredientsCost: $cost->ingredientsCost,
            additionalCost: $cost->additionalCost,
            totalCost: $cost->totalCost,
            costPerYieldUnit: $cost->costPerYieldUnit,
            costPerSaleUnit: $cost->costPerSaleUnit,
            details: [
                'lines' => $cost->lines,
                'additionalCosts' => array_map(static fn ($c) => ['description' => $c->getDescription(), 'amount' => $c->getAmount()], $recipe->getAdditionalCosts()),
                'warnings' => $cost->warnings,
            ],
            createdBy: $this->context->getUser()->getId(),
        );
        $this->repository->add($snapshot);
        $this->repository->flush();

        return $snapshot;
    }

    private function assertManufacturedProduct(Uuid $tenantId, Uuid $productId, string $field): void
    {
        $product = $this->catalog->product($tenantId, $productId)
            ?? throw DomainException::invalidField($field, 'Produto não encontrado.');
        if (ProductionType::MANUFACTURED !== $product->productionType) {
            throw DomainException::invalidField($field, 'Ficha técnica só pode ser criada para produto fabricado aqui.');
        }
    }

    private function assertNoDraft(Uuid $tenantId, Uuid $productId): void
    {
        if (null !== $this->repository->findByStatus($tenantId, $productId, RecipeStatus::DRAFT)) {
            throw DomainException::conflict('Este produto já tem uma versão em rascunho. Edite-a ou descarte-a antes.', 'DRAFT_EXISTS');
        }
    }

    /**
     * Regras de conteúdo (FR-021, DATABASE §3.11): ingredientes e unidades do
     * tenant, unidade do item na mesma dimensão da unidade de estoque do
     * ingrediente, rendimento na dimensão da unidade de venda do produto.
     */
    private function validate(Uuid $tenantId, Uuid $productId, RecipeContent $content): RecipeContent
    {
        if (Decimal::compare($content->expectedYieldQty, '0') <= 0) {
            throw DomainException::invalidField('expectedYieldQty', 'O rendimento deve ser maior que zero.');
        }

        $product = $this->catalog->product($tenantId, $productId) ?? throw DomainException::invalidField('productId', 'Produto não encontrado.');
        $yieldUnit = $this->catalog->unit($tenantId, $content->yieldUnitId)
            ?? throw DomainException::invalidField('yieldUnitId', 'Unidade de rendimento não encontrada.');
        if ($yieldUnit->getDimension() !== $product->saleUnit->getDimension()) {
            throw DomainException::invalidField('yieldUnitId', \sprintf('O rendimento precisa estar em uma unidade de %s, como a unidade de venda do produto (%s).', mb_strtolower($product->saleUnit->getDimension()->label()), $product->saleUnit->getCode()));
        }

        $ingredients = $this->catalog->ingredients($tenantId, array_map(static fn (RecipeItemData $i) => $i->ingredientId, $content->items));
        $seen = [];
        foreach ($content->items as $index => $item) {
            $key = $item->ingredientId->toRfc4122();
            $path = "items[{$index}]";
            $ingredient = $ingredients[$key] ?? throw DomainException::invalidField("{$path}[ingredientId]", 'Ingrediente não encontrado.');
            if (isset($seen[$key])) {
                throw DomainException::invalidField("{$path}[ingredientId]", \sprintf('%s aparece mais de uma vez. Some as quantidades em uma linha.', $ingredient->name));
            }
            $seen[$key] = true;

            if (Decimal::compare($item->quantity, '0') <= 0) {
                throw DomainException::invalidField("{$path}[quantity]", 'A quantidade deve ser maior que zero.');
            }
            $unit = $this->catalog->unit($tenantId, $item->unitId) ?? throw DomainException::invalidField("{$path}[unitId]", 'Unidade não encontrada.');
            if ($unit->getDimension() !== $ingredient->stockUnit->getDimension()) {
                throw DomainException::invalidField("{$path}[unitId]", \sprintf('%s é controlado em %s; use uma unidade de %s.', $ingredient->name, $ingredient->stockUnit->getCode(), mb_strtolower($ingredient->stockUnit->getDimension()->label())));
            }
        }

        return $content;
    }
}
