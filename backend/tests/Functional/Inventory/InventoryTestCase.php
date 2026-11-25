<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Tests\Functional\ApiTestCase;

abstract class InventoryTestCase extends ApiTestCase
{
    /**
     * Ingrediente estocado em gramas.
     *
     * @return array<string, mixed>
     */
    protected function createIngredient(string $name = 'Farinha de trigo', string $stockUnit = 'G'): array
    {
        $response = $this->api('POST', '/api/v1/ingredients', [
            'name' => $name,
            'stockUnitId' => $this->unitId($stockUnit),
            'averageUnitCost' => '0.0045',
            'minimumStock' => '0',
        ]);
        $this->assertStatus(201);

        return $response['data'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function opening(string $itemId, string $quantity, array $extra = [], string $itemType = 'INGREDIENT'): array
    {
        return $this->api('POST', '/api/v1/stock/opening-balances', [
            'itemType' => $itemType,
            'itemId' => $itemId,
            'quantity' => $quantity,
            ...$extra,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function adjust(string $itemId, string $type, string $quantity, array $extra = [], string $itemType = 'INGREDIENT'): array
    {
        return $this->api('POST', '/api/v1/stock/adjustments', [
            'itemType' => $itemType,
            'itemId' => $itemId,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => 'Motivo de teste',
            ...$extra,
        ]);
    }

    protected function balance(string $itemId, string $itemType = 'INGREDIENT'): string
    {
        $data = $this->api('GET', "/api/v1/stock/items/{$itemType}/{$itemId}")['data'];
        $this->assertStatus(200);

        return $data['totalQuantity'];
    }

    /**
     * Soma do ledger direto no banco, para conferir com o saldo exibido.
     */
    protected function ledgerSum(string $itemId): string
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        return (string) $connection->fetchOne(
            'SELECT COALESCE(SUM(quantity_delta), 0) FROM stock_movements WHERE ingredient_id = ? OR product_id = ?',
            [$itemId, $itemId],
        );
    }
}
