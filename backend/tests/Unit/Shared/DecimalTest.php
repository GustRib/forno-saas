<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    #[DataProvider('roundingCases')]
    public function testRoundsHalfUp(string $value, int $scale, string $expected): void
    {
        self::assertSame($expected, Decimal::round($value, $scale));
    }

    /**
     * @return iterable<array{string, int, string}>
     */
    public static function roundingCases(): iterable
    {
        yield ['1.005', 2, '1.01'];
        yield ['1.004', 2, '1.00'];
        yield ['-1.005', 2, '-1.01'];
        yield ['-0.001', 2, '0.00'];
        yield ['2.5', 0, '3'];
        yield ['0.1234565', 6, '0.123457'];
    }

    public function testArithmeticIsExact(): void
    {
        // 0.1 + 0.2 em float daria 0.30000000000000004.
        self::assertSame('0.30', Decimal::add('0.1', '0.2', 2));
        self::assertSame('0.333333', Decimal::div('1', '3'));
        self::assertSame('12.50', Decimal::mul('2.5', '5', 2));
    }

    public function testValidation(): void
    {
        self::assertTrue(Decimal::isValid('10'));
        self::assertTrue(Decimal::isValid('10.123456789'));
        self::assertFalse(Decimal::isValid('10,5'));
        self::assertFalse(Decimal::isValid('1e3'));
        self::assertFalse(Decimal::isValid(''));

        $this->expectException(DomainException::class);
        Decimal::assertValid('abc');
    }

    public function testDivisionByZero(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        Decimal::div('1', '0');
    }
}
