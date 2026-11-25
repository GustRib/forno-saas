<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * FNO-020 (categorias e unidades), FNO-021 (produtos), FNO-022 (ingredientes),
 * FNO-024 (conversão).
 */
final class CatalogTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTenant('Padaria Catálogo', 'dono@catalogo.test');
    }

    public function testCategoryCrud(): void
    {
        $category = $this->createCategory('Pães', 'SELLABLE');
        self::assertTrue($category['active']);

        $this->api('POST', '/api/v1/categories', ['name' => 'pães', 'kind' => 'SELLABLE']);
        $this->assertStatus(409);

        // Mesmo nome com outro tipo é permitido.
        $this->createCategory('Pães', 'INGREDIENT');

        $updated = $this->api('PUT', '/api/v1/categories/'.$category['id'], [
            'name' => 'Pães artesanais', 'kind' => 'SELLABLE', 'sortOrder' => 2, 'active' => false,
        ])['data'];
        self::assertSame('Pães artesanais', $updated['name']);
        self::assertFalse($updated['active']);

        $active = $this->api('GET', '/api/v1/categories?active=true')['data'];
        self::assertNotContains($category['id'], array_column($active, 'id'));
    }

    public function testUnitCreationUpdateAndDeletion(): void
    {
        $unit = $this->api('POST', '/api/v1/units', [
            'code' => 'sc25', 'name' => 'Saco 25 kg', 'dimension' => 'MASS', 'baseFactor' => '25000',
        ])['data'];
        $this->assertStatus(201);
        self::assertSame('SC25', $unit['code']);
        self::assertSame('25000', $unit['baseFactor']);

        $this->api('POST', '/api/v1/units', ['code' => 'SC25', 'name' => 'Outro', 'dimension' => 'MASS', 'baseFactor' => '1']);
        $this->assertStatus(409);

        $this->api('POST', '/api/v1/units', ['code' => 'ZERO', 'name' => 'Zero', 'dimension' => 'MASS', 'baseFactor' => '0']);
        $this->assertStatus(422);

        // Em uso → só o nome pode mudar.
        $this->createProduct(['saleUnitId' => $unit['id']]);
        $response = $this->api('PUT', '/api/v1/units/'.$unit['id'], [
            'code' => 'SC25', 'name' => 'Saco de 25 kg', 'dimension' => 'MASS', 'baseFactor' => '20000',
        ]);
        $this->assertStatus(422);
        self::assertSame('UNIT_IN_USE', $response['error']['code']);

        $renamed = $this->api('PUT', '/api/v1/units/'.$unit['id'], [
            'code' => 'SC25', 'name' => 'Saco de 25 kg', 'dimension' => 'MASS', 'baseFactor' => '25000',
        ])['data'];
        self::assertSame('Saco de 25 kg', $renamed['name']);

        $this->api('DELETE', '/api/v1/units/'.$unit['id']);
        $this->assertStatus(422);

        $this->api('DELETE', '/api/v1/units/'.$this->unitId('G'));
        $this->assertStatus(422); // unidade-base

        $this->api('DELETE', '/api/v1/units/'.$this->unitId('DZ'));
        $this->assertStatus(204);
    }

    public function testUnitConversionEndpoint(): void
    {
        $kg = $this->unitId('KG');
        $g = $this->unitId('G');

        $result = $this->api('GET', "/api/v1/units/convert?quantity=1.25&from={$kg}&to={$g}")['data'];
        $this->assertStatus(200);
        self::assertSame('1250.000000', $result['result']);

        $response = $this->api('GET', "/api/v1/units/convert?quantity=1&from={$kg}&to=".$this->unitId('L'));
        $this->assertStatus(422);
        self::assertSame('INCOMPATIBLE_UNITS', $response['error']['code']);
    }

    public function testProductCrudAndSearch(): void
    {
        $category = $this->createCategory('Pães', 'SELLABLE');
        $product = $this->createProduct([
            'name' => 'Pão francês', 'sku' => 'PF-01', 'barcode' => '7890000000017', 'categoryId' => $category['id'], 'salePrice' => '18.9',
        ]);
        self::assertSame('18.90', $product['salePrice']);
        self::assertTrue($product['stockTracked']);

        // SKU único por tenant, sem diferenciar maiúsculas.
        $this->api('POST', '/api/v1/products', [
            'name' => 'Sonho', 'sku' => 'pf-01', 'saleUnitId' => $this->unitId('UN'), 'productionType' => 'MANUFACTURED', 'salePrice' => '7.50',
        ]);
        $this->assertStatus(409);

        $this->createProduct(['name' => 'Sonho', 'sku' => 'SN-01', 'saleUnitId' => $this->unitId('UN'), 'salePrice' => '7.50']);
        $this->createProduct(['name' => 'Taxa de entrega', 'productionType' => 'SERVICE', 'stockTracked' => true, 'saleUnitId' => $this->unitId('UN'), 'salePrice' => '5.00']);

        $list = $this->api('GET', '/api/v1/products?limit=2');
        self::assertSame(3, $list['meta']['total']);
        self::assertCount(2, $list['data']);

        self::assertSame(['Pão francês'], array_column($this->api('GET', '/api/v1/products?q=franc')['data'], 'name'));
        self::assertSame(['Pão francês'], array_column($this->api('GET', '/api/v1/products?q=7890000000017')['data'], 'name'));
        self::assertSame(['Pão francês'], array_column($this->api('GET', '/api/v1/products?categoryId='.$category['id'])['data'], 'name'));

        $service = $this->api('GET', '/api/v1/products?productionType=SERVICE')['data'][0];
        self::assertFalse($service['stockTracked'], 'Serviço nunca controla estoque');

        $updated = $this->api('PUT', '/api/v1/products/'.$product['id'], [
            'name' => 'Pão francês', 'saleUnitId' => $this->unitId('KG'), 'productionType' => 'MANUFACTURED',
            'salePrice' => '19.50', 'sku' => 'PF-01', 'active' => false,
        ])['data'];
        self::assertSame('19.50', $updated['salePrice']);
        self::assertFalse($updated['active']);
        self::assertNull($updated['categoryId']);
        self::assertSame(2, $this->api('GET', '/api/v1/products?active=true')['meta']['total']);
    }

    public function testProductValidation(): void
    {
        $response = $this->api('POST', '/api/v1/products', [
            'name' => '', 'saleUnitId' => 'x', 'productionType' => 'OUTRO', 'salePrice' => '-1',
        ]);
        $this->assertStatus(422);
        foreach (['name', 'saleUnitId', 'productionType', 'salePrice'] as $field) {
            self::assertArrayHasKey($field, $response['error']['details']['fields']);
        }

        // Preço como número (float) é recusado: dinheiro trafega como string.
        $this->api('POST', '/api/v1/products', [
            'name' => 'Pão', 'saleUnitId' => $this->unitId('KG'), 'productionType' => 'RESALE', 'salePrice' => 1.1,
        ]);
        $this->assertStatus(422);

        $ingredientCategory = $this->createCategory('Farinhas', 'INGREDIENT');
        $response = $this->api('POST', '/api/v1/products', [
            'name' => 'Pão', 'saleUnitId' => $this->unitId('KG'), 'productionType' => 'RESALE', 'salePrice' => '1.00', 'categoryId' => $ingredientCategory['id'],
        ]);
        $this->assertStatus(422);
        self::assertArrayHasKey('categoryId', $response['error']['details']['fields']);
    }

    public function testIngredientWithPurchaseUnitConversion(): void
    {
        $category = $this->createCategory('Farinhas', 'INGREDIENT');

        // Compra em KG, estoque em G: fator calculado automaticamente.
        $flour = $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Farinha de trigo', 'categoryId' => $category['id'], 'sku' => 'FT-01',
            'stockUnitId' => $this->unitId('G'), 'purchaseUnitId' => $this->unitId('KG'),
            'averageUnitCost' => '0.0045', 'minimumStock' => '5000',
        ])['data'];
        $this->assertStatus(201);
        self::assertSame('1000', $flour['purchaseToStockFactor']);
        self::assertSame('0.0045', $flour['averageUnitCost']);

        // Compra por unidade (dimensão diferente) exige fator explícito.
        $response = $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Ovos', 'stockUnitId' => $this->unitId('G'), 'purchaseUnitId' => $this->unitId('DZ'),
            'averageUnitCost' => '0', 'minimumStock' => '0',
        ]);
        $this->assertStatus(422);
        self::assertArrayHasKey('purchaseToStockFactor', $response['error']['details']['fields']);

        $eggs = $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Ovos', 'stockUnitId' => $this->unitId('G'), 'purchaseUnitId' => $this->unitId('DZ'),
            'purchaseToStockFactor' => '600', 'averageUnitCost' => '0.02', 'minimumStock' => '0',
        ])['data'];
        $this->assertStatus(201);
        self::assertSame('600', $eggs['purchaseToStockFactor']);

        $this->api('POST', '/api/v1/ingredients', [
            'name' => 'Duplicado', 'sku' => 'ft-01', 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0', 'minimumStock' => '0',
        ]);
        $this->assertStatus(409);

        $sellable = $this->createCategory('Pães', 'SELLABLE');
        $this->api('PUT', '/api/v1/ingredients/'.$flour['id'], [
            'name' => 'Farinha de trigo', 'categoryId' => $sellable['id'], 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0', 'minimumStock' => '0',
        ]);
        $this->assertStatus(422);

        $updated = $this->api('PUT', '/api/v1/ingredients/'.$flour['id'], [
            'name' => 'Farinha de trigo tipo 1', 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0.005', 'minimumStock' => '10000',
        ])['data'];
        self::assertSame('Farinha de trigo tipo 1', $updated['name']);
        self::assertNull($updated['purchaseUnitId']);
        self::assertNull($updated['purchaseToStockFactor']);
        self::assertSame(2, $this->api('GET', '/api/v1/ingredients')['meta']['total']);
    }
}
