<?php

declare(strict_types=1);

namespace App\Recipe\Application;

use App\Catalog\Application\RecipeUsageChecker;
use App\Recipe\Infrastructure\RecipeRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Implementa a porta do Catalog que impede mudar a dimensão de unidades
 * usadas por receitas abertas (rascunho ou ativa).
 */
final class RecipeUsage implements RecipeUsageChecker
{
    public function __construct(private readonly RecipeRepository $repository)
    {
    }

    public function isIngredientInOpenRecipe(Uuid $tenantId, Uuid $ingredientId): bool
    {
        return $this->repository->isIngredientInOpenRecipe($tenantId, $ingredientId);
    }

    public function productHasOpenRecipe(Uuid $tenantId, Uuid $productId): bool
    {
        return $this->repository->productHasOpenRecipe($tenantId, $productId);
    }
}
