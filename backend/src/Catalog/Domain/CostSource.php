<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * Origem de um custo de ingrediente (FR-025).
 */
enum CostSource: string
{
    /** Custo que o ingrediente já tinha quando o histórico começou (migração). */
    case INITIAL = 'INITIAL';
    /** Informado no cadastro do ingrediente (FR-014). */
    case MANUAL = 'MANUAL';
    /** Recalculado por uma entrada de estoque com custo (política WAC-1). */
    case STOCK_MOVEMENT = 'STOCK_MOVEMENT';

    public function label(): string
    {
        return match ($this) {
            self::INITIAL => 'Custo inicial do cadastro',
            self::MANUAL => 'Informado no cadastro',
            self::STOCK_MOVEMENT => 'Média ponderada após entrada de estoque',
        };
    }
}
