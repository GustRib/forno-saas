<?php

declare(strict_types=1);

namespace App\Recipe\Domain;

/**
 * DATABASE §3.10. Só DRAFT é editável; ACTIVE só pode ser arquivada;
 * ARCHIVED é histórico imutável (FR-023). O banco garante o mesmo.
 */
enum RecipeStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::ACTIVE => 'Ativa',
            self::ARCHIVED => 'Arquivada',
        };
    }
}
