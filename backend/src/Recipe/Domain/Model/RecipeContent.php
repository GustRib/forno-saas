<?php

declare(strict_types=1);

namespace App\Recipe\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * Conteúdo editável de uma versão (já validado pelo caso de uso).
 */
final readonly class RecipeContent
{
    /**
     * @param list<RecipeItemData>                             $items
     * @param list<array{description: string, amount: string}> $additionalCosts
     */
    public function __construct(
        public string $name,
        public string $expectedYieldQty,
        public Uuid $yieldUnitId,
        public ?string $instructions,
        public array $items,
        public array $additionalCosts,
    ) {
    }
}
