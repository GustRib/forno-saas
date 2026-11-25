<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Domain\WeightedAverageCost;
use PHPUnit\Framework\TestCase;

/**
 * Política WAC-1 (DECISIONS D-17).
 */
final class WeightedAverageCostTest extends TestCase
{
    public function testFirstCostedEntryOnEmptyStockSetsTheCost(): void
    {
        self::assertSame('0.004000', WeightedAverageCost::afterInbound('0', '0.009', '10000', '0.004'));
    }

    public function testWeightsByQuantity(): void
    {
        // 10 kg a 0,004/g + 10 kg a 0,005/g = 0,0045/g
        self::assertSame('0.004500', WeightedAverageCost::afterInbound('10000', '0.004', '10000', '0.005'));
        // 30 a 2,00 + 10 a 4,00 = 2,50
        self::assertSame('2.500000', WeightedAverageCost::afterInbound('30', '2', '10', '4'));
    }

    public function testRoundsHalfUpToSixDecimals(): void
    {
        // (1×1 + 2×0) / 3 = 0,3333333… → 0,333333 ; (2×1 + 1×0)/3 = 0,6666666… → 0,666667
        self::assertSame('0.333333', WeightedAverageCost::afterInbound('1', '1', '2', '0'));
        self::assertSame('0.666667', WeightedAverageCost::afterInbound('2', '1', '1', '0'));
    }

    public function testNoPrecisionLossWithTinyQuantities(): void
    {
        self::assertSame('0.004500', WeightedAverageCost::afterInbound('0.000001', '0.0045', '0.000001', '0.0045'));
    }

    public function testReversalUndoesTheEntryExactlyWhenNothingWasConsumed(): void
    {
        $after = WeightedAverageCost::afterInbound('10000', '0.004', '10000', '0.005');
        self::assertSame('0.004000', WeightedAverageCost::afterInboundReversal('20000', $after, '10000', '0.005'));
    }

    public function testReversalThatEmptiesStockKeepsCost(): void
    {
        self::assertNull(WeightedAverageCost::afterInboundReversal('100', '3', '100', '3'));
    }

    public function testReversalThatWouldMakeValueNegativeKeepsCost(): void
    {
        // Entrou 5 a 100 sobre 10 a 1 (média 34), consumiu 9 → restam 6 valendo 204;
        // tirar 5 a 100 (500) deixaria valor negativo.
        self::assertNull(WeightedAverageCost::afterInboundReversal('6', '34', '5', '100'));
    }

    public function testRejectsNegativeInputs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WeightedAverageCost::afterInbound('-1', '1', '1', '1');
    }
}
