<?php

declare(strict_types=1);

namespace App\Recipe\Domain;

use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Shared\Domain\CostPolicy;
use App\Shared\Domain\Decimal;

/**
 * FNO-042: custo estimado por lote e por unidade produzida.
 *
 *  linha        = quantidade convertida p/ unidade de estoque × custo vigente do ingrediente
 *  ingredientes = Σ linhas;  adicionais = Σ custos adicionais (FR-024)
 *  total        = ingredientes + adicionais
 *  por unidade de rendimento = total ÷ rendimento esperado
 *  por unidade de venda      = por unidade de rendimento × (1 unidade de venda em unidades de rendimento)
 *
 * Tudo em escala 12; arredondamento "half up" só no final: dinheiro em 2
 * casas, custos unitários e quantidades em 6 (DECISIONS D-17). Os valores
 * por unidade vêm do total não arredondado (sem arredondar duas vezes).
 * Massa e volume nunca são somados nem convertidos entre si (REQUIREMENTS §5.4).
 */
final class RecipeCostCalculator
{
    /**
     * @param list<CostLine> $lines
     * @param list<string>   $additionalAmounts
     */
    public function calculate(array $lines, array $additionalAmounts, string $yieldQty, UnitOfMeasure $yieldUnit, ?UnitOfMeasure $saleUnit): RecipeCost
    {
        $s = CostPolicy::INTERNAL_SCALE;
        $warnings = [];
        $complete = [] !== $lines;
        if ([] === $lines) {
            $warnings[] = ['code' => 'NO_ITEMS', 'message' => 'A receita não tem ingredientes.'];
        }

        $ingredientsRaw = '0';
        $outLines = [];
        foreach ($lines as $line) {
            $out = [
                'ingredientId' => $line->ingredientId,
                'ingredientName' => $line->ingredientName,
                'quantity' => self::trim($line->quantity),
                'unitCode' => $line->unit->getCode(),
                'stockQuantity' => null,
                'stockUnitCode' => $line->stockUnit?->getCode(),
                'unitCost' => null !== $line->unitCost ? self::trim($line->unitCost) : null,
                'lineCost' => null,
                'costChangedAt' => $line->costChangedAt?->format(\DATE_ATOM),
                'costSource' => $line->costSource,
            ];

            if (null === $line->stockUnit || null === $line->unitCost) {
                $complete = false;
                $warnings[] = ['code' => 'INGREDIENT_MISSING', 'message' => 'Ingrediente não encontrado no cadastro.', 'ingredientId' => $line->ingredientId];
                $outLines[] = $out;
                continue;
            }

            if ($line->unit->getDimension() !== $line->stockUnit->getDimension()) {
                $complete = false;
                $warnings[] = [
                    'code' => 'UNIT_INCOMPATIBLE',
                    'message' => \sprintf('%s: %s não pode ser convertido para %s.', $line->ingredientName, $line->unit->getCode(), $line->stockUnit->getCode()),
                    'ingredientId' => $line->ingredientId,
                ];
                $outLines[] = $out;
                continue;
            }

            $stockQuantity = bcdiv(bcmul($line->quantity, $line->unit->getBaseFactor(), $s), $line->stockUnit->getBaseFactor(), $s);
            $lineRaw = bcmul($stockQuantity, $line->unitCost, $s);
            $ingredientsRaw = bcadd($ingredientsRaw, $lineRaw, $s);

            $out['stockQuantity'] = self::trim(Decimal::round($stockQuantity, CostPolicy::UNIT_SCALE));
            $out['lineCost'] = Decimal::round($lineRaw, CostPolicy::UNIT_SCALE);
            $outLines[] = $out;

            if (0 === bccomp($line->unitCost, '0', $s)) {
                $warnings[] = [
                    'code' => 'ZERO_COST',
                    'message' => \sprintf('%s está com custo zero. Confirme o custo no cadastro do ingrediente.', $line->ingredientName),
                    'ingredientId' => $line->ingredientId,
                ];
            }
            if (!$line->ingredientActive) {
                $warnings[] = [
                    'code' => 'INACTIVE_INGREDIENT',
                    'message' => \sprintf('%s está inativo no cadastro.', $line->ingredientName),
                    'ingredientId' => $line->ingredientId,
                ];
            }
        }

        $additionalRaw = '0';
        foreach ($additionalAmounts as $amount) {
            $additionalRaw = bcadd($additionalRaw, $amount, $s);
        }
        $totalRaw = bcadd($ingredientsRaw, $additionalRaw, $s);

        $perYieldRaw = bcdiv($totalRaw, $yieldQty, $s);

        $perSale = null;
        if (null === $saleUnit || $saleUnit->getDimension() !== $yieldUnit->getDimension()) {
            $complete = false;
            $warnings[] = [
                'code' => 'YIELD_UNIT_INCOMPATIBLE',
                'message' => 'O rendimento não pode ser convertido para a unidade de venda do produto.',
            ];
        } else {
            $yieldUnitsPerSaleUnit = bcdiv($saleUnit->getBaseFactor(), $yieldUnit->getBaseFactor(), $s);
            $perSale = Decimal::round(bcmul($perYieldRaw, $yieldUnitsPerSaleUnit, $s), CostPolicy::UNIT_SCALE);
        }

        return new RecipeCost(
            lines: $outLines,
            ingredientsCost: Decimal::round($ingredientsRaw, CostPolicy::MONEY_SCALE),
            additionalCost: Decimal::round($additionalRaw, CostPolicy::MONEY_SCALE),
            totalCost: Decimal::round($totalRaw, CostPolicy::MONEY_SCALE),
            costPerYieldUnit: Decimal::round($perYieldRaw, CostPolicy::UNIT_SCALE),
            costPerSaleUnit: $perSale,
            complete: $complete,
            warnings: $warnings,
        );
    }

    private static function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
