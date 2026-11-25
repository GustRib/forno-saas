<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Porta implementada pelo módulo Recipe. "Aberta" = rascunho ou ativa.
 * O Catalog a usa para não quebrar receitas com mudanças de dimensão de
 * unidade (massa ↔ volume exigiria densidade, REQUIREMENTS §5.4).
 */
interface RecipeUsageChecker
{
    public function isIngredientInOpenRecipe(Uuid $tenantId, Uuid $ingredientId): bool;

    public function productHasOpenRecipe(Uuid $tenantId, Uuid $productId): bool;
}
