<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * Tipos de item que podem ter estoque (stock_movements.ingredient_id / product_id).
 */
enum StockItemType: string
{
    case INGREDIENT = 'INGREDIENT';
    case PRODUCT = 'PRODUCT';

    public function label(): string
    {
        return match ($this) {
            self::INGREDIENT => 'Ingrediente',
            self::PRODUCT => 'Produto',
        };
    }
}
