<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Model\UnitOfMeasure;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;

/**
 * FNO-024: conversão de quantidades entre unidades da mesma dimensão.
 *
 * Cada unidade tem um fator para a unidade-base da sua dimensão
 * (ex.: KG = 1000 × G). Conversão = quantidade × fatorOrigem ÷ fatorDestino.
 * Massa ↔ volume exige densidade explícita e não é feita aqui.
 */
final class UnitConverter
{
    public function convert(string $quantity, UnitOfMeasure $from, UnitOfMeasure $to, int $scale = 6): string
    {
        Decimal::assertValid($quantity, 'quantity');

        if ($from->getDimension() !== $to->getDimension()) {
            throw new IncompatibleUnitsException($from->getDimension(), $to->getDimension());
        }

        if ($from->getId()->equals($to->getId())) {
            return Decimal::round($quantity, $scale);
        }

        $inBase = bcmul($quantity, $from->getBaseFactor(), 12);

        return Decimal::div($inBase, $to->getBaseFactor(), $scale);
    }

    /**
     * Converte sem arredondar: se o resultado não couber exatamente em
     * $scale casas (ex.: 1 UN em DZ = 0,0833…), recusa. Usado no ledger de
     * estoque, onde arredondar criaria diferença permanente (REQUIREMENTS §5.2).
     */
    public function convertExact(string $quantity, UnitOfMeasure $from, UnitOfMeasure $to, int $scale = 6): string
    {
        Decimal::assertValid($quantity, 'quantity');

        if ($from->getDimension() !== $to->getDimension()) {
            throw new IncompatibleUnitsException($from->getDimension(), $to->getDimension());
        }

        $raw = bcdiv(bcmul($quantity, $from->getBaseFactor(), 18), $to->getBaseFactor(), 18);
        $rounded = Decimal::round($raw, $scale);
        if (0 !== bccomp($raw, $rounded, 18)) {
            throw new DomainException(\sprintf('A quantidade não pode ser convertida para %s sem arredondamento. Informe-a diretamente em %s.', $to->getCode(), $to->getCode()), 'INEXACT_CONVERSION', 422, ['fields' => ['quantity' => ['Conversão exigiria arredondamento.']]]);
        }

        return $rounded;
    }

    /**
     * Fator multiplicativo para converter 1 unidade de $from em $to.
     */
    public function factor(UnitOfMeasure $from, UnitOfMeasure $to): string
    {
        return $this->convert('1', $from, $to);
    }
}
