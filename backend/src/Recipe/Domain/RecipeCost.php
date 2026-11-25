<?php

declare(strict_types=1);

namespace App\Recipe\Domain;

/**
 * Resultado do cálculo de custo estimado de uma versão (FR-022, FR-024, FR-025).
 * Totais em dinheiro com 2 casas; custos unitários com 6 casas (D-17).
 */
final readonly class RecipeCost
{
    /**
     * @param list<array<string, mixed>>                                        $lines
     * @param list<array{code: string, message: string, ingredientId?: string}> $warnings
     */
    public function __construct(
        public array $lines,
        public string $ingredientsCost,
        public string $additionalCost,
        public string $totalCost,
        public string $costPerYieldUnit,
        public ?string $costPerSaleUnit,
        public bool $complete,
        public array $warnings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'ingredientsCost' => $this->ingredientsCost,
            'additionalCost' => $this->additionalCost,
            'totalCost' => $this->totalCost,
            'costPerYieldUnit' => $this->costPerYieldUnit,
            'costPerSaleUnit' => $this->costPerSaleUnit,
            'complete' => $this->complete,
            'warnings' => $this->warnings,
        ];
    }
}
