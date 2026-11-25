<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * FNO-012 / NFR-002: usuário do tenant A não lê nem altera dados do tenant B.
 */
final class TenantIsolationTest extends ApiTestCase
{
    /** @var array<string, string> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerTenant('Padaria A', 'dono@a.test');
        $category = $this->createCategory('Pães A', 'SELLABLE');
        $ingredientCategory = $this->createCategory('Farinhas A', 'INGREDIENT');
        $product = $this->createProduct(['name' => 'Pão secreto A', 'sku' => 'A-001', 'categoryId' => $category['id']]);
        $ingredient = $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Farinha A',
            'stockUnitId' => $this->unitId('G'),
            'averageUnitCost' => '0.005',
            'minimumStock' => '1000',
        ])['data'];

        $this->a = [
            'category' => $category['id'],
            'ingredientCategory' => $ingredientCategory['id'],
            'product' => $product['id'],
            'ingredient' => $ingredient['id'],
            'unitKg' => $this->unitId('KG'),
        ];

        $this->registerTenant('Padaria B', 'dono@b.test');
    }

    public function testCannotReadOtherTenantRecordsById(): void
    {
        $this->api('GET', '/api/v1/products/'.$this->a['product']);
        $this->assertStatus(404);
        $this->api('GET', '/api/v1/ingredients/'.$this->a['ingredient']);
        $this->assertStatus(404);
        $this->api('GET', '/api/v1/categories/'.$this->a['category']);
        $this->assertStatus(404);
    }

    public function testListingsOnlyShowOwnTenant(): void
    {
        self::assertSame(0, $this->api('GET', '/api/v1/products')['meta']['total']);
        self::assertSame(0, $this->api('GET', '/api/v1/ingredients')['meta']['total']);
        self::assertSame([], $this->api('GET', '/api/v1/categories')['data']);

        $unitIds = array_column($this->api('GET', '/api/v1/units')['data'], 'id');
        self::assertNotContains($this->a['unitKg'], $unitIds);
    }

    public function testSearchBySkuDoesNotLeak(): void
    {
        self::assertSame(0, $this->api('GET', '/api/v1/products?q=A-001')['meta']['total']);
    }

    public function testCannotUpdateOtherTenantRecords(): void
    {
        $this->api('PUT', '/api/v1/products/'.$this->a['product'], [
            'name' => 'Invadido',
            'saleUnitId' => $this->unitId('KG'),
            'productionType' => 'RESALE',
            'salePrice' => '0.01',
        ]);
        $this->assertStatus(404);

        $this->api('PUT', '/api/v1/categories/'.$this->a['category'], ['name' => 'Invadida', 'kind' => 'BOTH']);
        $this->assertStatus(404);

        $this->api('DELETE', '/api/v1/units/'.$this->a['unitKg']);
        $this->assertStatus(404);

        // Confirma, como dono de A, que nada mudou.
        $this->login('dono@a.test');
        $product = $this->api('GET', '/api/v1/products/'.$this->a['product'])['data'];
        self::assertSame('Pão secreto A', $product['name']);
        self::assertSame('18.90', $product['salePrice']);
    }

    public function testCannotReferenceOtherTenantCategoryOrUnit(): void
    {
        $response = $this->api('POST', '/api/v1/products', [
            'name' => 'Pão B',
            'saleUnitId' => $this->unitId('KG'),
            'productionType' => 'MANUFACTURED',
            'salePrice' => '10.00',
            'categoryId' => $this->a['category'],
        ]);
        $this->assertStatus(422);
        self::assertArrayHasKey('categoryId', $response['error']['details']['fields']);

        $response = $this->api('POST', '/api/v1/products', [
            'name' => 'Pão B',
            'saleUnitId' => $this->a['unitKg'],
            'productionType' => 'MANUFACTURED',
            'salePrice' => '10.00',
        ]);
        $this->assertStatus(422);
        self::assertArrayHasKey('saleUnitId', $response['error']['details']['fields']);

        $response = $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Farinha B',
            'stockUnitId' => $this->unitId('G'),
            'averageUnitCost' => '0',
            'minimumStock' => '0',
            'categoryId' => $this->a['ingredientCategory'],
        ]);
        $this->assertStatus(422);
    }

    public function testSameSkuIsAllowedInDifferentTenants(): void
    {
        $this->createProduct(['name' => 'Pão B', 'sku' => 'A-001']);
        $this->assertStatus(201);
    }

    public function testCannotSelectTenantWithoutMembership(): void
    {
        $session = $this->login('dono@a.test')['data'];
        $tenantA = $session['activeTenant']['id'];

        $this->login('dono@b.test');
        $this->api('POST', '/api/v1/session/tenant', ['tenantId' => $tenantA]);
        $this->assertStatus(404);

        $me = $this->api('GET', '/api/v1/auth/me')['data'];
        self::assertSame('Padaria B', $me['activeTenant']['name']);
    }

    public function testDatabaseRejectsCrossTenantReferenceEvenWithoutApplicationCheck(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $tenantB = $connection->fetchOne("SELECT id FROM tenants WHERE name = 'Padaria B'");

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $connection->insert('products', [
            'id' => '0199a000-0000-7000-8000-000000000001',
            'tenant_id' => $tenantB,
            'name' => 'Produto forjado',
            'sale_unit_id' => $this->a['unitKg'],
            'production_type' => 'RESALE',
        ]);
    }
}
