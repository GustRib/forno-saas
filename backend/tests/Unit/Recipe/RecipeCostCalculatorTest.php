<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recipe;

use App\Catalog\Domain\Dimension;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Recipe\Domain\CostLine;
use App\Recipe\Domain\RecipeCostCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * FNO-042 / FNO-044 — inclui o exemplo de aceite do BACKLOG:
 * 10 kg de farinha, 6 L de água, 200 g de fermento e 200 g de sal.
 */
final class RecipeCostCalculatorTest extends TestCase
{
    /** @var array<string, UnitOfMeasure> */
    private array $u = [];

    protected function setUp(): void
    {
        $tenant = Uuid::v7();
        foreach ([['G', Dimension::MASS, '1'], ['KG', Dimension::MASS, '1000'], ['ML', Dimension::VOLUME, '1'], ['L', Dimension::VOLUME, '1000'], ['UN', Dimension::COUNT, '1'], ['DZ', Dimension::COUNT, '12']] as [$code, $dim, $factor]) {
            $this->u[$code] = new UnitOfMeasure($tenant, $code, $code, $dim, $factor, '1' === $factor);
        }
    }

    private function line(string $name, string $qty, string $unit, string $stockUnit, string $cost, bool $active = true): CostLine
    {
        return new CostLine(Uuid::v7()->toRfc4122(), $name, $qty, $this->u[$unit], $this->u[$stockUnit], $cost, $active);
    }

    public function testBacklogAcceptanceExample(): void
    {
        $result = (new RecipeCostCalculator())->calculate(
            [
                $this->line('Farinha', '10', 'KG', 'G', '0.0045'),   // 10 000 g × 0,0045 = 45,00
                $this->line('Água', '6', 'L', 'ML', '0.000005'),     // 6 000 ml × 0,000005 = 0,03
                $this->line('Fermento', '200', 'G', 'G', '0.04'),    // 200 g × 0,04 = 8,00
                $this->line('Sal', '200', 'G', 'KG', '2'),           // 0,2 kg × 2,00 = 0,40
            ],
            ['3.00', '4.50'],                                        // embalagem + energia
            '17',
            $this->u['KG'],
            $this->u['KG'],
        );

        self::assertTrue($result->complete);
        self::assertSame('53.43', $result->ingredientsCost);
        self::assertSame('7.50', $result->additionalCost);
        self::assertSame('60.93', $result->totalCost);
        self::assertSame('3.584118', $result->costPerYieldUnit);    // 60,93 / 17 = 3,5841176…
        self::assertSame('3.584118', $result->costPerSaleUnit);
        self::assertSame(['10000', '6000', '200', '0.2'], array_column($result->lines, 'stockQuantity'));
        self::assertSame(['45.000000', '0.030000', '8.000000', '0.400000'], array_column($result->lines, 'lineCost'));
        self::assertSame([], $result->warnings);
    }

    public function testCostPerSaleUnitConvertsFromYieldUnit(): void
    {
        // 60 unidades por lote, vendido por dúzia: custo por dúzia = 12 × custo por unidade.
        $result = (new RecipeCostCalculator())->calculate([$this->line('Farinha', '1', 'KG', 'G', '0.006')], [], '60', $this->u['UN'], $this->u['DZ']);

        self::assertSame('6.00', $result->totalCost);
        self::assertSame('0.100000', $result->costPerYieldUnit);
        self::assertSame('1.200000', $result->costPerSaleUnit);

        // Rendimento em kg, vendido por 100 g não existe aqui, mas g ↔ kg sim:
        $grams = (new RecipeCostCalculator())->calculate([$this->line('Farinha', '1', 'KG', 'G', '0.006')], [], '1.5', $this->u['KG'], $this->u['G']);
        self::assertSame('4.000000', $grams->costPerYieldUnit);
        self::assertSame('0.004000', $grams->costPerSaleUnit);
    }

    public function testPerUnitCostUsesUnroundedTotal(): void
    {
        // Três linhas de 0,004 → total bruto 0,012 (R$ 0,01 arredondado); por unidade 0,012/3 = 0,004, não 0,01/3.
        $result = (new RecipeCostCalculator())->calculate([
            $this->line('A', '1', 'G', 'G', '0.004'),
            $this->line('B', '1', 'G', 'G', '0.004'),
            $this->line('C', '1', 'G', 'G', '0.004'),
        ], [], '3', $this->u['UN'], $this->u['UN']);

        self::assertSame('0.01', $result->ingredientsCost);
        self::assertSame('0.004000', $result->costPerYieldUnit);
    }

    public function testMoneyRoundsHalfUp(): void
    {
        $result = (new RecipeCostCalculator())->calculate([$this->line('A', '1', 'G', 'G', '0.005')], [], '1', $this->u['UN'], $this->u['UN']);
        self::assertSame('0.01', $result->totalCost);
    }

    public function testZeroCostAndInactiveIngredientsAreFlaggedButComplete(): void
    {
        $result = (new RecipeCostCalculator())->calculate([
            $this->line('Água', '6', 'L', 'ML', '0'),
            $this->line('Sal antigo', '10', 'G', 'G', '0.002', active: false),
        ], [], '1', $this->u['KG'], $this->u['KG']);

        self::assertTrue($result->complete);
        self::assertSame(['ZERO_COST', 'INACTIVE_INGREDIENT'], array_column($result->warnings, 'code'));
    }

    public function testIncompatibleOrMissingDataMakesResultIncomplete(): void
    {
        $missing = new CostLine(Uuid::v7()->toRfc4122(), null, '1', $this->u['G'], null, null);
        $result = (new RecipeCostCalculator())->calculate([
            $this->line('Leite', '1', 'KG', 'ML', '0.006'),   // massa → volume: sem densidade
            $missing,
        ], [], '10', $this->u['KG'], $this->u['UN']);           // rendimento kg × venda un

        self::assertFalse($result->complete);
        self::assertEqualsCanonicalizing(['UNIT_INCOMPATIBLE', 'INGREDIENT_MISSING', 'YIELD_UNIT_INCOMPATIBLE'], array_column($result->warnings, 'code'));
        self::assertNull($result->lines[0]['lineCost']);
        self::assertSame('0.00', $result->ingredientsCost);
        self::assertNull($result->costPerSaleUnit);
    }

    public function testEmptyRecipeIsIncomplete(): void
    {
        $result = (new RecipeCostCalculator())->calculate([], ['2.00'], '10', $this->u['UN'], $this->u['UN']);

        self::assertFalse($result->complete);
        self::assertSame('NO_ITEMS', $result->warnings[0]['code']);
        self::assertSame('2.00', $result->totalCost);
        self::assertSame('0.200000', $result->costPerYieldUnit);
    }
}
