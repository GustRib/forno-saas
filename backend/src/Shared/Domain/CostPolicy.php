<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Versão da política de custo em vigor (REQUIREMENTS §5.9: "explícita e
 * versionada"). Descrita em docs/DECISIONS.md, D-17. Gravada em cada mudança
 * de custo feita pelo estoque e em cada snapshot de custo de receita, para que
 * resultados antigos continuem interpretáveis se a política mudar.
 */
final class CostPolicy
{
    /** Custo médio ponderado por ingrediente e tenant, todos os locais. */
    public const CURRENT = 'WAC-1';

    /** Escala dos custos unitários e quantidades persistidos (NUMERIC(18,6)). */
    public const UNIT_SCALE = 6;

    /** Escala dos totais em dinheiro (NUMERIC(14,2)). */
    public const MONEY_SCALE = 2;

    /** Escala interna dos cálculos antes do arredondamento final. */
    public const INTERNAL_SCALE = 12;
}
