<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

enum CategoryKind: string
{
    case SELLABLE = 'SELLABLE';
    case INGREDIENT = 'INGREDIENT';
    case BOTH = 'BOTH';

    public function acceptsProducts(): bool
    {
        return self::INGREDIENT !== $this;
    }

    public function acceptsIngredients(): bool
    {
        return self::SELLABLE !== $this;
    }
}
