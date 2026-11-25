<?php

declare(strict_types=1);

namespace App\Recipe\Presentation;

use App\Catalog\Application\ProductView;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Recipe\Domain\Model\Recipe;
use App\Recipe\Domain\Model\RecipeCostSnapshot;
use App\Recipe\Domain\RecipeCost;

final class RecipeSerializer
{
    /**
     * @param array<string, UnitOfMeasure> $units
     *
     * @return array<string, mixed>
     */
    public function summary(Recipe $r, ?ProductView $product, array $units): array
    {
        $yieldUnit = $units[$r->getYieldUnitId()->toRfc4122()] ?? null;

        return [
            'id' => $r->getId()->toRfc4122(),
            'productId' => $r->getProductId()->toRfc4122(),
            'productName' => $product?->name,
            'version' => $r->getVersion(),
            'name' => $r->getName(),
            'status' => $r->getStatus()->value,
            'statusLabel' => $r->getStatus()->label(),
            'expectedYieldQty' => self::trim($r->getExpectedYieldQty()),
            'yieldUnit' => null !== $yieldUnit ? self::unit($yieldUnit) : null,
            'effectiveFrom' => $r->getEffectiveFrom()?->format('Y-m-d'),
            'activatedAt' => $r->getActivatedAt()?->format(\DATE_ATOM),
            'archivedAt' => $r->getArchivedAt()?->format(\DATE_ATOM),
            'createdAt' => $r->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $r->getUpdatedAt()->format(\DATE_ATOM),
            'itemsCount' => \count($r->getItems()),
        ];
    }

    /**
     * @param array<string, UnitOfMeasure> $units
     * @param list<Recipe>                 $versions
     *
     * @return array<string, mixed>
     */
    public function detail(Recipe $r, ?ProductView $product, array $units, RecipeCost $cost, array $versions): array
    {
        $costLines = [];
        foreach ($cost->lines as $line) {
            $costLines[$line['ingredientId']] = $line;
        }

        return [
            ...$this->summary($r, $product, $units),
            'product' => null !== $product ? [
                'id' => $product->id->toRfc4122(),
                'name' => $product->name,
                'productionType' => $product->productionType->value,
                'saleUnit' => self::unit($product->saleUnit),
            ] : null,
            'instructions' => $r->getInstructions(),
            'items' => array_map(fn ($item) => [
                'id' => $item->getId()->toRfc4122(),
                'ingredientId' => $item->getIngredientId()->toRfc4122(),
                'ingredientName' => $costLines[$item->getIngredientId()->toRfc4122()]['ingredientName'] ?? null,
                'quantity' => self::trim($item->getQuantity()),
                'unit' => isset($units[$item->getUnitId()->toRfc4122()]) ? self::unit($units[$item->getUnitId()->toRfc4122()]) : null,
                'notes' => $item->getNotes(),
            ], $r->getItems()),
            'additionalCosts' => array_map(static fn ($c) => [
                'id' => $c->getId()->toRfc4122(),
                'description' => $c->getDescription(),
                'amount' => $c->getAmount(),
            ], $r->getAdditionalCosts()),
            'cost' => $cost->toArray(),
            'versions' => array_map(static fn (Recipe $v) => [
                'id' => $v->getId()->toRfc4122(),
                'version' => $v->getVersion(),
                'status' => $v->getStatus()->value,
                'statusLabel' => $v->getStatus()->label(),
                'effectiveFrom' => $v->getEffectiveFrom()?->format('Y-m-d'),
            ], $versions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(RecipeCostSnapshot $s): array
    {
        return [
            'id' => $s->getId()->toRfc4122(),
            'recipeId' => $s->getRecipeId()->toRfc4122(),
            'reason' => $s->getReason(),
            'costPolicy' => $s->getCostPolicy(),
            'ingredientsCost' => $s->getIngredientsCost(),
            'additionalCost' => $s->getAdditionalCost(),
            'totalCost' => $s->getTotalCost(),
            'costPerYieldUnit' => $s->getCostPerYieldUnit(),
            'costPerSaleUnit' => $s->getCostPerSaleUnit(),
            'yieldQty' => self::trim($s->getYieldQty()),
            'details' => $s->getDetails(),
            'createdAt' => $s->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function unit(UnitOfMeasure $u): array
    {
        return ['id' => $u->getId()->toRfc4122(), 'code' => $u->getCode(), 'name' => $u->getName(), 'dimension' => $u->getDimension()->value];
    }

    private static function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
