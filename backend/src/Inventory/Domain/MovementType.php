<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Tipos de movimentação (ARCHITECTURE §9). Todos existem no banco desde já;
 * neste épico só os manuais (saldo inicial, perdas, devoluções, ajustes e
 * estornos) podem ser lançados. Compra, produção e venda chegam com seus módulos.
 */
enum MovementType: string
{
    case OPENING_BALANCE = 'OPENING_BALANCE';
    case PURCHASE_RECEIPT = 'PURCHASE_RECEIPT';
    case PRODUCTION_CONSUMPTION = 'PRODUCTION_CONSUMPTION';
    case PRODUCTION_OUTPUT = 'PRODUCTION_OUTPUT';
    case SALE = 'SALE';
    case WASTE = 'WASTE';
    case ADJUSTMENT_IN = 'ADJUSTMENT_IN';
    case ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';
    case RETURN_IN = 'RETURN_IN';
    case REVERSAL = 'REVERSAL';

    /** Tipos aceitos em POST /stock/adjustments (FR-035). */
    public const ADJUSTMENT_TYPES = [self::WASTE, self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::RETURN_IN];

    public function label(): string
    {
        return match ($this) {
            self::OPENING_BALANCE => 'Saldo inicial',
            self::PURCHASE_RECEIPT => 'Recebimento de compra',
            self::PRODUCTION_CONSUMPTION => 'Consumo na produção',
            self::PRODUCTION_OUTPUT => 'Entrada da produção',
            self::SALE => 'Venda',
            self::WASTE => 'Perda / descarte',
            self::ADJUSTMENT_IN => 'Ajuste de entrada',
            self::ADJUSTMENT_OUT => 'Ajuste de saída',
            self::RETURN_IN => 'Devolução',
            self::REVERSAL => 'Estorno',
        };
    }

    /** Sinal da quantidade (o banco também verifica: ck_stock_movements_sign). */
    public function isInbound(): bool
    {
        return \in_array($this, [self::OPENING_BALANCE, self::PURCHASE_RECEIPT, self::PRODUCTION_OUTPUT, self::ADJUSTMENT_IN, self::RETURN_IN], true);
    }

    public function requiresReason(): bool
    {
        return \in_array($this, [self::WASTE, self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::RETURN_IN, self::REVERSAL], true);
    }

    /**
     * Pode ser estornado pela tela de estoque. Movimentos de compra, produção
     * e venda serão estornados pelos seus próprios fluxos (cancelamentos).
     */
    public function isManuallyReversible(): bool
    {
        return \in_array($this, [self::OPENING_BALANCE, self::WASTE, self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::RETURN_IN], true);
    }

    /** Entrada que aceita custo unitário informado. */
    public function acceptsUnitCost(): bool
    {
        return \in_array($this, [self::OPENING_BALANCE, self::ADJUSTMENT_IN, self::RETURN_IN], true);
    }
}
