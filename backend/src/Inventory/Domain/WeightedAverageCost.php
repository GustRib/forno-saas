<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Shared\Domain\CostPolicy;
use App\Shared\Domain\Decimal;

/**
 * Política de custo WAC-1 (DECISIONS D-17): custo médio ponderado por
 * ingrediente, considerando o saldo de todos os locais do tenant.
 *
 * Só entradas com custo informado (e o estorno delas) mudam o custo médio.
 * Saídas consomem pelo custo médio vigente; entradas sem custo são
 * valorizadas pelo custo médio vigente — em ambos os casos ele não muda.
 *
 * Cálculo em escala 12 (bcmath), resultado arredondado "half up" para 6 casas.
 */
final class WeightedAverageCost
{
    /**
     * Novo custo após uma entrada de $quantityIn a $unitCostIn, havendo
     * $quantityBefore em estoque a $averageBefore.
     */
    public static function afterInbound(string $quantityBefore, string $averageBefore, string $quantityIn, string $unitCostIn): string
    {
        self::assertNonNegative($quantityBefore, $averageBefore, $quantityIn, $unitCostIn);
        $s = CostPolicy::INTERNAL_SCALE;

        $totalQuantity = bcadd($quantityBefore, $quantityIn, $s);
        if (0 === bccomp($totalQuantity, '0', $s)) {
            return Decimal::round($averageBefore, CostPolicy::UNIT_SCALE);
        }
        if (0 === bccomp($quantityBefore, '0', $s)) {
            return Decimal::round($unitCostIn, CostPolicy::UNIT_SCALE);
        }

        $value = bcadd(bcmul($quantityBefore, $averageBefore, $s), bcmul($quantityIn, $unitCostIn, $s), $s);

        return Decimal::round(bcdiv($value, $totalQuantity, $s), CostPolicy::UNIT_SCALE);
    }

    /**
     * Novo custo ao estornar uma entrada de $quantityOut que entrou a
     * $unitCostOut. Devolve null quando o custo deve permanecer o mesmo:
     *  - o estorno zera o estoque (não há o que reavaliar);
     *  - o valor restante ficaria negativo (houve consumo pelo custo médio
     *    depois da entrada e a reversão exata não é possível).
     */
    public static function afterInboundReversal(string $quantityBefore, string $averageBefore, string $quantityOut, string $unitCostOut): ?string
    {
        self::assertNonNegative($quantityBefore, $averageBefore, $quantityOut, $unitCostOut);
        $s = CostPolicy::INTERNAL_SCALE;

        $remaining = bcsub($quantityBefore, $quantityOut, $s);
        if (bccomp($remaining, '0', $s) <= 0) {
            return null;
        }

        $value = bcsub(bcmul($quantityBefore, $averageBefore, $s), bcmul($quantityOut, $unitCostOut, $s), $s);
        if (bccomp($value, '0', $s) < 0) {
            return null;
        }

        return Decimal::round(bcdiv($value, $remaining, $s), CostPolicy::UNIT_SCALE);
    }

    private static function assertNonNegative(string ...$values): void
    {
        foreach ($values as $value) {
            if (bccomp($value, '0', CostPolicy::INTERNAL_SCALE) < 0) {
                throw new \InvalidArgumentException('Quantidades e custos do custo médio não podem ser negativos.');
            }
        }
    }
}
