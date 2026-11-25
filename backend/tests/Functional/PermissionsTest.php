<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * FNO-013 / FR-004: autorização por ação validada no backend.
 */
final class PermissionsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTenant('Padaria Papéis', 'dono@papeis.test');
        $this->createProduct();
        $this->addMember('caixa@papeis.test', 'CASHIER');
        $this->addMember('gerente@papeis.test', 'MANAGER');
    }

    public function testCashierCanReadCatalogButNotWrite(): void
    {
        $this->login('caixa@papeis.test');

        $this->api('GET', '/api/v1/products');
        $this->assertStatus(200);

        $response = $this->api('POST', '/api/v1/products', [
            'name' => 'Tentativa',
            'saleUnitId' => $this->unitId('UN'),
            'productionType' => 'RESALE',
            'salePrice' => '1.00',
        ]);
        $this->assertStatus(403);
        self::assertSame('FORBIDDEN', $response['error']['code']);

        $this->api('POST', '/api/v1/categories', ['name' => 'X', 'kind' => 'BOTH']);
        $this->assertStatus(403);
    }

    public function testCashierCannotListOrManageMembers(): void
    {
        $this->login('caixa@papeis.test');

        $this->api('GET', '/api/v1/members');
        $this->assertStatus(403);
        $this->api('POST', '/api/v1/members', ['name' => 'X', 'email' => 'x@x.test', 'role' => 'OWNER', 'password' => 'senha-segura-123']);
        $this->assertStatus(403);
    }

    public function testManagerCanWriteCatalogButNotManageMembers(): void
    {
        $this->login('gerente@papeis.test');

        $this->createCategory('Doces', 'SELLABLE');
        $this->api('GET', '/api/v1/members');
        $this->assertStatus(200);
        $this->api('POST', '/api/v1/members', ['name' => 'X', 'email' => 'x@x.test', 'role' => 'CASHIER', 'password' => 'senha-segura-123']);
        $this->assertStatus(403);
    }

    public function testSessionExposesRolePermissions(): void
    {
        $session = $this->login('caixa@papeis.test')['data'];

        self::assertSame('CASHIER', $session['activeTenant']['role']);
        self::assertSame(['catalog.read'], $session['activeTenant']['permissions']);
    }
}
