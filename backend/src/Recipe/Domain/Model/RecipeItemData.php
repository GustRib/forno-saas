<?php

declare(strict_types=1);

namespace App\Recipe\Domain\Model;

use Symfony\Component\Uid\Uuid;

final readonly class RecipeItemData
{
    public function __construct(
        public Uuid $ingredientId,
        public string $quantity,
        public Uuid $unitId,
        public ?string $notes = null,
    ) {
    }
}
