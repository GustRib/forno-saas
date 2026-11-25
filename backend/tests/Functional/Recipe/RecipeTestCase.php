<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recipe;

use App\Tests\Functional\Inventory\InventoryTestCase;

abstract class RecipeTestCase extends InventoryTestCase
{
    /** @var array<string, string> ids dos itens do exemplo do BACKLOG */
    protected array $ids = [];

    /**
     * Padaria com os ingredientes do exemplo de aceite e um pão francês (kg).
     * Custos: farinha 0,0045/g · água 0,005/L · fermento 0,04/g · sal 2,00/kg.
     */
    protected function setUpBakery(string $name, string $email): void
    {
        $this->registerTenant($name, $email);
        $make = function (string $n, string $unit, string $cost): string {
            $response = $this->api('POST', '/api/v1/ingredients', ['name' => $n, 'stockUnitId' => $this->unitId($unit), 'averageUnitCost' => $cost, 'minimumStock' => '0']);
            $this->assertStatus(201);

            return $response['data']['id'];
        };
        $this->ids = [
            'flour' => $make('Farinha de trigo', 'G', '0.0045'),
            'water' => $make('Água', 'L', '0.005'),
            'yeast' => $make('Fermento', 'G', '0.04'),
            'salt' => $make('Sal', 'KG', '2'),
            'bread' => $this->createProduct(['name' => 'Pão francês', 'saleUnitId' => $this->unitId('KG')])['id'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function examplePayload(array $overrides = []): array
    {
        return [
            'productId' => $this->ids['bread'],
            'name' => 'Pão francês tradicional',
            'expectedYieldQty' => '17',
            'yieldUnitId' => $this->unitId('KG'),
            'instructions' => 'Sovar 12 minutos. Fermentar 2 h.',
            'items' => [
                ['ingredientId' => $this->ids['flour'], 'quantity' => '10', 'unitId' => $this->unitId('KG')],
                ['ingredientId' => $this->ids['water'], 'quantity' => '6', 'unitId' => $this->unitId('L')],
                ['ingredientId' => $this->ids['yeast'], 'quantity' => '200', 'unitId' => $this->unitId('G')],
                ['ingredientId' => $this->ids['salt'], 'quantity' => '200', 'unitId' => $this->unitId('G'), 'notes' => 'Sal refinado'],
            ],
            'additionalCosts' => [
                ['description' => 'Embalagem', 'amount' => '3.00'],
                ['description' => 'Energia do forno', 'amount' => '4.50'],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function createRecipe(array $overrides = []): array
    {
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload($overrides));
        $this->assertStatus(201);

        return $response['data'];
    }
}
