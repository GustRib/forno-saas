<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Dimension;
use App\Catalog\Domain\IncompatibleUnitsException;
use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Catalog\Domain\UnitConverter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * FNO-024 — aceite: kg↔g, L↔ml e unidade↔unidade; incompatíveis são rejeitadas.
 */
final class UnitConverterTest extends TestCase
{
    private UnitConverter $converter;

    /** @var array<string, UnitOfMeasure> */
    private array $u = [];

    protected function setUp(): void
    {
        $this->converter = new UnitConverter();
        $tenant = Uuid::v7();
        foreach ([
            ['G', Dimension::MASS, '1'],
            ['KG', Dimension::MASS, '1000'],
            ['ML', Dimension::VOLUME, '1'],
            ['L', Dimension::VOLUME, '1000'],
            ['UN', Dimension::COUNT, '1'],
            ['DZ', Dimension::COUNT, '12'],
        ] as [$code, $dimension, $factor]) {
            $this->u[$code] = new UnitOfMeasure($tenant, $code, $code, $dimension, $factor, '1' === $factor);
        }
    }

    public function testKilogramsToGrams(): void
    {
        self::assertSame('1500.000000', $this->converter->convert('1.5', $this->u['KG'], $this->u['G']));
        self::assertSame('0.200000', $this->converter->convert('200', $this->u['G'], $this->u['KG']));
    }

    public function testLitersToMilliliters(): void
    {
        self::assertSame('6000.000000', $this->converter->convert('6', $this->u['L'], $this->u['ML']));
        self::assertSame('0.250000', $this->converter->convert('250', $this->u['ML'], $this->u['L']));
    }

    public function testCountUnits(): void
    {
        self::assertSame('24.000000', $this->converter->convert('2', $this->u['DZ'], $this->u['UN']));
        self::assertSame('7.000000', $this->converter->convert('7', $this->u['UN'], $this->u['UN']));
    }

    public function testDoesNotRoundPrematurely(): void
    {
        self::assertSame('0.000500', $this->converter->convert('0.5', $this->u['G'], $this->u['KG']));
        self::assertSame('0.001000', $this->converter->factor($this->u['G'], $this->u['KG']));
        self::assertSame('0.083333', $this->converter->convert('1', $this->u['UN'], $this->u['DZ']));
    }

    public function testExactConversionKeepsValuesThatFit(): void
    {
        self::assertSame('1500.000000', $this->converter->convertExact('1.5', $this->u['KG'], $this->u['G']));
        self::assertSame('0.000001', $this->converter->convertExact('0.001', $this->u['G'], $this->u['KG']));
        self::assertSame('2.000000', $this->converter->convertExact('24', $this->u['UN'], $this->u['DZ']));
    }

    public function testExactConversionRefusesToRound(): void
    {
        try {
            $this->converter->convertExact('1', $this->u['UN'], $this->u['DZ']); // 0,08333…
            self::fail('Deveria recusar conversão inexata.');
        } catch (\App\Shared\Domain\DomainException $e) {
            self::assertSame('INEXACT_CONVERSION', $e->errorCode);
        }

        $this->expectException(\App\Shared\Domain\DomainException::class);
        $this->converter->convertExact('0.0001', $this->u['G'], $this->u['KG']); // 0,0000001 kg
    }

    public function testExactConversionRejectsOtherDimension(): void
    {
        $this->expectException(IncompatibleUnitsException::class);
        $this->converter->convertExact('1', $this->u['L'], $this->u['G']);
    }

    public function testRejectsMassToVolume(): void
    {
        $this->expectException(IncompatibleUnitsException::class);
        $this->converter->convert('1', $this->u['KG'], $this->u['L']);
    }

    public function testRejectsCountToMass(): void
    {
        $this->expectException(IncompatibleUnitsException::class);
        $this->converter->convert('1', $this->u['UN'], $this->u['G']);
    }
}
