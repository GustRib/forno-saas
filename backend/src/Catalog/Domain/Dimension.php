<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * Dimensão física de uma unidade. Conversões só são permitidas dentro da
 * mesma dimensão (REQUIREMENTS §5, regras 3 e 4).
 */
enum Dimension: string
{
    case MASS = 'MASS';
    case VOLUME = 'VOLUME';
    case COUNT = 'COUNT';
    case LENGTH = 'LENGTH';

    public function label(): string
    {
        return match ($this) {
            self::MASS => 'Massa',
            self::VOLUME => 'Volume',
            self::COUNT => 'Contagem',
            self::LENGTH => 'Comprimento',
        };
    }
}
