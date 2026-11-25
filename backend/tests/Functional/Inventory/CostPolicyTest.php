<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

/**
 * Política de custo WAC-1 aplicada pelo ledger, e histórico de custo
 * (FNO-044, FR-025). DECISIONS D-17.
 */
final class CostPolicyTest extends InventoryTestCase
{
    /** @var array<string, mixed> */
    private array $flour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTenant('Padaria Custo', 'dono@custo.test');
        $response = $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Farinha', 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0.009', 'minimumStock' => '0',
        ]);
        $this->assertStatus(201);
        $this->flour = $response['data'];
    }

    private function cost(): string
    {
        return $this->api('GET', '/api/v1/ingredients/'.$this->flour['id'])['data']['averageUnitCost'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        $response = $this->api('GET', '/api/v1/ingredients/'.$this->flour['id'].'/cost-history');
        $this->assertStatus(200);

        return $response['data'];
    }

    public function testRegistrationCostIsTheFirstHistoryEntry(): void
    {
        $history = $this->history();

        self::assertCount(1, $history);
        self::assertSame('MANUAL', $history[0]['source']);
        self::assertNull($history[0]['previousCost']);
        self::assertSame('0.009', $history[0]['newCost']);
        self::assertSame('Dono Padaria Custo', $history[0]['changedBy']['name']);
    }

    public function testCostedEntriesRecalculateWeightedAverage(): void
    {
        // Estoque vazio: a primeira entrada com custo define o custo.
        $opening = $this->opening($this->flour['id'], '10', ['unitId' => $this->unitId('KG'), 'unitCost' => '0.004'])['data'];
        self::assertSame('0.004', $this->cost());

        // 10 000 g a 0,004 + 10 000 g a 0,005 → 0,0045
        $entry = $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '10000', ['unitCost' => '0.005', 'reason' => 'Contagem'])['data'];
        self::assertSame('0.0045', $this->cost());

        $history = $this->history();
        self::assertSame(['STOCK_MOVEMENT', 'STOCK_MOVEMENT', 'MANUAL'], array_column($history, 'source'));
        self::assertSame([$entry['id'], $opening['id'], null], array_column($history, 'stockMovementId'));
        self::assertSame(['WAC-1', 'WAC-1', null], array_column($history, 'costPolicy'));
        self::assertSame(['0.004', '0.009', null], array_column($history, 'previousCost'));
    }

    public function testOutflowsAndUncostedEntriesDoNotChangeCost(): void
    {
        $this->opening($this->flour['id'], '20000', ['unitCost' => '0.0045']);
        $this->adjust($this->flour['id'], 'WASTE', '5000');
        $this->adjust($this->flour['id'], 'ADJUSTMENT_OUT', '1');
        $this->adjust($this->flour['id'], 'RETURN_IN', '100');
        $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '100');

        self::assertSame('0.0045', $this->cost());
        self::assertCount(2, $this->history()); // cadastro + saldo inicial
    }

    public function testOpeningBalanceWithoutCostKeepsRegisteredCost(): void
    {
        $this->opening($this->flour['id'], '5000');

        self::assertSame('0.009', $this->cost());
        self::assertCount(1, $this->history());
    }

    public function testReversingCostedEntryRestoresAverage(): void
    {
        $this->opening($this->flour['id'], '10000', ['unitCost' => '0.004']);
        $entry = $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '10000', ['unitCost' => '0.005'])['data'];
        self::assertSame('0.0045', $this->cost());

        $reversal = $this->api('POST', "/api/v1/stock/movements/{$entry['id']}/reversal", ['reason' => 'Preço errado'])['data'];
        $this->assertStatus(201);
        self::assertSame('0.004', $this->cost());
        self::assertSame($reversal['id'], $this->history()[0]['stockMovementId']);

        // Estornar uma saída não mexe no custo.
        $waste = $this->adjust($this->flour['id'], 'WASTE', '100')['data'];
        $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => 'Engano']);
        self::assertSame('0.004', $this->cost());
    }

    public function testReversalThatEmptiesStockKeepsCost(): void
    {
        $opening = $this->opening($this->flour['id'], '1000', ['unitCost' => '0.007'])['data'];
        self::assertSame('0.007', $this->cost());

        $this->api('POST', "/api/v1/stock/movements/{$opening['id']}/reversal", ['reason' => 'Contagem errada']);
        $this->assertStatus(201);
        self::assertSame('0.007', $this->cost());
    }

    public function testManualEditIsRecordedOnlyWhenCostChanges(): void
    {
        $payload = ['name' => 'Farinha', 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0.009', 'minimumStock' => '0'];

        $this->api('PUT', '/api/v1/ingredients/'.$this->flour['id'], [...$payload, 'minimumStock' => '1000']);
        $this->assertStatus(200);
        self::assertCount(1, $this->history());

        $this->api('PUT', '/api/v1/ingredients/'.$this->flour['id'], [...$payload, 'averageUnitCost' => '0.0052']);
        $history = $this->history();
        self::assertCount(2, $history);
        self::assertSame(['MANUAL', '0.009', '0.0052'], [$history[0]['source'], $history[0]['previousCost'], $history[0]['newCost']]);

        // A próxima entrada com custo pondera a partir do valor editado.
        $this->opening($this->flour['id'], '1000', ['unitCost' => '0.006']);
        self::assertSame('0.006', $this->cost()); // estoque estava vazio
        $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '1000', ['unitCost' => '0.004']);
        self::assertSame('0.005', $this->cost());
    }

    public function testProductEntriesWithCostDoNotCreateCostHistory(): void
    {
        $bread = $this->createProduct(['name' => 'Baguete', 'saleUnitId' => $this->unitId('UN')]);
        $movement = $this->opening($bread['id'], '10', ['unitCost' => '1.50'], 'PRODUCT')['data'];

        $this->assertStatus(201);
        self::assertSame('1.5', $movement['unitCost']);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ingredient_cost_history WHERE stock_movement_id = ?', [$movement['id']]));
    }

    public function testCostHistoryIsTenantScopedAndAppendOnly(): void
    {
        $this->registerTenant('Outra Padaria', 'dono@outra-custo.test');
        $this->api('GET', '/api/v1/ingredients/'.$this->flour['id'].'/cost-history');
        $this->assertStatus(404);

        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $connection->beginTransaction();
        try {
            $connection->executeStatement('UPDATE ingredient_cost_history SET new_cost = 1 WHERE ingredient_id = ?', [$this->flour['id']]);
            self::fail('Histórico de custo deveria ser imutável.');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('23001', $e->getSQLState());
        } finally {
            $connection->rollBack();
        }
    }
}
