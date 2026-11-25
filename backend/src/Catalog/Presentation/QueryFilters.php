<?php

declare(strict_types=1);

namespace App\Catalog\Presentation;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Leitura tolerante de filtros de query string (valores inválidos são ignorados).
 */
final class QueryFilters
{
    public static function bool(Request $request, string $name): ?bool
    {
        return match ($request->query->get($name)) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    public static function uuid(Request $request, string $name): ?Uuid
    {
        $value = (string) $request->query->get($name, '');

        return Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
