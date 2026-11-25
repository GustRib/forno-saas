<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Aritmética decimal exata (bcmath) para dinheiro e quantidades.
 * Valores trafegam como string ("12.500000"); nunca usar float (NFR-005/006).
 */
final class Decimal
{
    private const INTERNAL_SCALE = 12;

    public const PATTERN = '/^-?\d{1,12}(\.\d{1,9})?$/';

    public static function isValid(string $value): bool
    {
        return 1 === preg_match(self::PATTERN, $value);
    }

    public static function assertValid(string $value, string $field = 'valor'): void
    {
        if (!self::isValid($value)) {
            throw DomainException::invalidField($field, \sprintf('O campo "%s" deve ser um número decimal válido.', $field));
        }
    }

    public static function mul(string $a, string $b, int $scale = 6): string
    {
        return self::round(bcmul($a, $b, self::INTERNAL_SCALE), $scale);
    }

    public static function div(string $a, string $b, int $scale = 6): string
    {
        if (0 === bccomp($b, '0', self::INTERNAL_SCALE)) {
            throw new \DivisionByZeroError('Divisão por zero.');
        }

        return self::round(bcdiv($a, $b, self::INTERNAL_SCALE), $scale);
    }

    public static function add(string $a, string $b, int $scale = 6): string
    {
        return self::round(bcadd($a, $b, self::INTERNAL_SCALE), $scale);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::INTERNAL_SCALE);
    }

    /**
     * Arredondamento "half up" (meio para longe do zero) na escala indicada.
     */
    public static function round(string $value, int $scale): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $offset, $scale)
            : bcadd($value, $offset, $scale);

        // Evita "-0.00".
        if (0 === bccomp($rounded, '0', $scale)) {
            return bcadd('0', '0', $scale);
        }

        return $rounded;
    }
}
