<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

/**
 * Isolamento de tenant (NFR-002) e autorização por ação (FR-004) no estoque.
 */
final class InventoryIsolationAndPermissionsTest extends InventoryTestCase
{
    public function testTenantCannotReadOrMoveAnotherTenantsStock(): void
    {
        $this->registerTenant('Padaria A', 'dono@estoque-a.test');
        $flour = $this->createIngredient();
        $opening = $this->opening($flour['id'], '1000')['data'];
        $locationA = $this->api('GET', '/api/v1/stock-locations')['data'][0]['id'];

        $this->registerTenant('Padaria B', 'dono@estoque-b.test');
        $ownFlour = $this->createIngredient();

        $this->api('GET', '/api/v1/stock/items/INGREDIENT/'.$flour['id']);
        $this->assertStatus(404);
        $this->api('GET', '/api/v1/stock/items/INGREDIENT/'.$flour['id'].'/movements');
        $this->assertStatus(404);

        // Lançar no item de A: recusado como item inexistente.
        $response = $this->adjust($flour['id'], 'WASTE', '1000');
        $this->assertStatus(422);
        self::assertArrayHasKey('itemId', $response['error']['details']['fields']);

        // Usar o local de A no próprio item: recusado.
        $response = $this->opening($ownFlour['id'], '1', ['locationId' => $locationA]);
        $this->assertStatus(422);
        self::assertArrayHasKey('locationId', $response['error']['details']['fields']);
        $this->api('GET', '/api/v1/stock/balances?locationId='.$locationA);
        $this->assertStatus(404);

        // Estornar movimento de A: não encontrado.
        $this->api('POST', "/api/v1/stock/movements/{$opening['id']}/reversal", ['reason' => 'Invasão']);
        $this->assertStatus(404);

        $locationsB = array_column($this->api('GET', '/api/v1/stock-locations')['data'], 'id');
        self::assertNotContains($locationA, $locationsB);
        self::assertSame([$ownFlour['id']], array_column($this->api('GET', '/api/v1/stock/balances')['data'], 'itemId'));

        // O estoque de A continua intacto.
        $this->login('dono@estoque-a.test');
        self::assertSame('1000', $this->balance($flour['id']));
    }

    public function testDatabaseRejectsCrossTenantLedgerReferences(): void
    {
        $this->registerTenant('Padaria A', 'dono@fk-a.test');
        $flour = $this->createIngredient();
        $unitA = $this->unitId('G');
        $this->registerTenant('Padaria B', 'dono@fk-b.test');

        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $tenantB = $connection->fetchOne("SELECT id FROM tenants WHERE name = 'Padaria B'");
        $locationB = $connection->fetchOne('SELECT id FROM stock_locations WHERE tenant_id = ?', [$tenantB]);
        $userB = $connection->fetchOne("SELECT id FROM users WHERE email = 'dono@fk-b.test'");

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $connection->insert('stock_movements', [
            'id' => '0199a000-0000-7000-8000-0000000000aa',
            'tenant_id' => $tenantB,
            'ingredient_id' => $flour['id'],
            'stock_location_id' => $locationB,
            'movement_type' => 'ADJUSTMENT_IN',
            'quantity_delta' => '10',
            'unit_id' => $unitA,
            'source_type' => 'MANUAL',
            'reason' => 'forjado',
            'occurred_at' => '2026-11-25 10:00:00+00',
            'created_by' => $userB,
        ]);
    }

    public function testRolePermissions(): void
    {
        $this->registerTenant('Padaria Papéis', 'dono@estoque-papeis.test');
        $flour = $this->createIngredient();
        $waste = null;
        $this->opening($flour['id'], '1000');
        $this->addMember('caixa@estoque-papeis.test', 'CASHIER');
        $this->addMember('estoque@estoque-papeis.test', 'STOCK');
        $this->addMember('producao@estoque-papeis.test', 'PRODUCTION');
        $this->addMember('gerente@estoque-papeis.test', 'MANAGER');

        // Caixa: sem acesso ao estoque.
        $this->login('caixa@estoque-papeis.test');
        $this->api('GET', '/api/v1/stock/balances');
        $this->assertStatus(403);
        $this->adjust($flour['id'], 'WASTE', '1');
        $this->assertStatus(403);

        // Estoque: lê e lança, mas não estorna.
        $this->login('estoque@estoque-papeis.test');
        $this->api('GET', '/api/v1/stock/balances');
        $this->assertStatus(200);
        $waste = $this->adjust($flour['id'], 'WASTE', '10')['data'];
        $this->assertStatus(201);
        $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => 'Erro']);
        $this->assertStatus(403);

        // Produção: registra perdas.
        $this->login('producao@estoque-papeis.test');
        $this->adjust($flour['id'], 'WASTE', '5');
        $this->assertStatus(201);

        // Gerente: estorna.
        $this->login('gerente@estoque-papeis.test');
        $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => 'Erro de lançamento']);
        $this->assertStatus(201);

        self::assertSame('995', $this->balance($flour['id']));
    }
}
