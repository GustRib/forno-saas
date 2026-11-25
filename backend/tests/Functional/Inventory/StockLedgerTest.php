<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

/**
 * FNO-030 (ledger imutável), FNO-031 (saldo inicial), FNO-032 (saldo por
 * item e local), FNO-033 (perdas e ajustes com motivo).
 *
 * Aceite do épico: saldo exibido = soma dos movimentos; nada deixa o saldo negativo.
 */
final class StockLedgerTest extends InventoryTestCase
{
    /** @var array<string, mixed> */
    private array $flour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTenant('Padaria Estoque', 'dono@estoque.test');
        $this->flour = $this->createIngredient();
    }

    public function testTenantGetsDefaultLocation(): void
    {
        $locations = $this->api('GET', '/api/v1/stock-locations')['data'];

        $this->assertStatus(200);
        self::assertCount(1, $locations);
        self::assertSame('Estoque geral', $locations[0]['name']);
        self::assertTrue($locations[0]['isDefault']);
    }

    public function testOpeningBalanceConvertsToStockUnit(): void
    {
        $movement = $this->opening($this->flour['id'], '1.5', ['unitId' => $this->unitId('KG'), 'unitCost' => '0.0045'])['data'];

        $this->assertStatus(201);
        self::assertSame('OPENING_BALANCE', $movement['type']);
        self::assertSame('1500', $movement['quantityDelta']);
        self::assertSame($this->unitId('G'), $movement['unitId']);
        self::assertSame('0.0045', $movement['unitCost']);
        self::assertSame('MANUAL', $movement['sourceType']);
        self::assertSame('1500', $this->balance($this->flour['id']));

        // Custo do ingrediente não é recalculado: a política de custo está em aberto.
        self::assertSame('0.0045', $this->api('GET', '/api/v1/ingredients/'.$this->flour['id'])['data']['averageUnitCost']);
    }

    public function testOpeningBalanceOnlyOncePerItemAndLocation(): void
    {
        $this->opening($this->flour['id'], '1000');
        $this->assertStatus(201);

        $response = $this->opening($this->flour['id'], '500');
        $this->assertStatus(409);
        self::assertSame('OPENING_BALANCE_NOT_ALLOWED', $response['error']['code']);

        // Depois de outro movimento qualquer, também não.
        $other = $this->createIngredient('Sal');
        $this->adjust($other['id'], 'ADJUSTMENT_IN', '10');
        $this->opening($other['id'], '100');
        $this->assertStatus(409);
    }

    public function testWasteAndAdjustmentsKeepBalanceEqualToLedgerSum(): void
    {
        $this->opening($this->flour['id'], '10000');
        $this->adjust($this->flour['id'], 'WASTE', '250.5', ['reason' => 'Saco rasgado']);
        $this->assertStatus(201);
        $this->adjust($this->flour['id'], 'ADJUSTMENT_OUT', '0.000001', ['reason' => 'Contagem']);
        $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '1', ['unitId' => $this->unitId('KG'), 'reason' => 'Contagem física']);
        $this->adjust($this->flour['id'], 'RETURN_IN', '100', ['reason' => 'Devolução da produção']);

        $expected = '10849.499999';
        self::assertSame($expected, $this->balance($this->flour['id']));
        self::assertSame(0, bccomp($expected, $this->ledgerSum($this->flour['id']), 6));

        $history = $this->api('GET', '/api/v1/stock/items/INGREDIENT/'.$this->flour['id'].'/movements')['data'];
        self::assertSame(['RETURN_IN', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'WASTE', 'OPENING_BALANCE'], array_column($history, 'type'));
        self::assertSame(['100', '1000', '-0.000001', '-250.5', '10000'], array_column($history, 'quantityDelta'));
        self::assertSame('Saco rasgado', $history[3]['reason']);
        self::assertSame('Dono Padaria Estoque', $history[0]['createdBy']['name']);
    }

    public function testBlocksNegativeStock(): void
    {
        $big = $this->createIngredient('Açúcar');
        $this->opening($big['id'], '25500.5');
        $response = $this->adjust($big['id'], 'WASTE', '30000');
        self::assertSame('Estoque insuficiente: há 25.500,5 G disponível.', $response['error']['message']);
        self::assertSame('25500.5', $response['error']['details']['available']);

        $this->opening($this->flour['id'], '100');

        $response = $this->adjust($this->flour['id'], 'WASTE', '100.000001');
        $this->assertStatus(422);
        self::assertSame('INSUFFICIENT_STOCK', $response['error']['code']);
        self::assertSame('100', $response['error']['details']['available']);

        // Item sem nenhuma entrada também não aceita saída.
        $salt = $this->createIngredient('Sal');
        $this->adjust($salt['id'], 'ADJUSTMENT_OUT', '1');
        $this->assertStatus(422);

        // Zerar exatamente é permitido.
        $this->adjust($this->flour['id'], 'WASTE', '100');
        $this->assertStatus(201);
        self::assertSame('0', $this->balance($this->flour['id']));
    }

    public function testReasonIsRequired(): void
    {
        $this->opening($this->flour['id'], '100');

        $response = $this->adjust($this->flour['id'], 'WASTE', '1', ['reason' => '   ']);
        $this->assertStatus(422);
        self::assertArrayHasKey('reason', $response['error']['details']['fields']);

        $response = $this->adjust($this->flour['id'], 'WASTE', '1', ['reason' => null]);
        $this->assertStatus(422);
        self::assertArrayHasKey('reason', $response['error']['details']['fields']);
    }

    public function testValidatesQuantityUnitAndCost(): void
    {
        $this->opening($this->flour['id'], '0');
        $this->assertStatus(422);

        $response = $this->opening($this->flour['id'], '1,5');
        $this->assertStatus(422);
        self::assertArrayHasKey('quantity', $response['error']['details']['fields']);

        // Quantidade como número JSON é recusada (decimais trafegam como string).
        $this->api('POST', '/api/v1/stock/opening-balances', ['itemType' => 'INGREDIENT', 'itemId' => $this->flour['id'], 'quantity' => 1.5]);
        $this->assertStatus(422);

        $response = $this->opening($this->flour['id'], '1', ['unitId' => $this->unitId('L')]);
        $this->assertStatus(422);
        self::assertSame('INCOMPATIBLE_UNITS', $response['error']['code']);

        // 0,0001 g não cabe em kg com 6 casas: recusa em vez de arredondar.
        $kgItem = $this->createIngredient('Fermento', 'KG');
        $response = $this->opening($kgItem['id'], '0.0001', ['unitId' => $this->unitId('G')]);
        $this->assertStatus(422);
        self::assertSame('INEXACT_CONVERSION', $response['error']['code']);

        $this->opening($this->flour['id'], '100');
        $response = $this->adjust($this->flour['id'], 'WASTE', '1', ['unitCost' => '1.00']);
        $this->assertStatus(422);
        self::assertArrayHasKey('unitCost', $response['error']['details']['fields']);

        $response = $this->adjust($this->flour['id'], 'SALE', '1');
        $this->assertStatus(422);
        self::assertArrayHasKey('type', $response['error']['details']['fields']);
    }

    public function testOccurredAtCanBeBackdatedButNotInFuture(): void
    {
        $movement = $this->opening($this->flour['id'], '100', ['occurredAt' => '2026-11-01T06:00:00-03:00'])['data'];
        $this->assertStatus(201);
        self::assertSame('2026-11-01T09:00:00+00:00', $movement['occurredAt']);

        $future = (new \DateTimeImmutable('+1 day'))->format(\DATE_ATOM);
        $response = $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '1', ['occurredAt' => $future]);
        $this->assertStatus(422);
        self::assertArrayHasKey('occurredAt', $response['error']['details']['fields']);

        $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '1', ['occurredAt' => 'ontem']);
        $this->assertStatus(422);
    }

    public function testReversalRestoresBalanceOnceAndIsTraceable(): void
    {
        $this->opening($this->flour['id'], '500');
        $waste = $this->adjust($this->flour['id'], 'WASTE', '200', ['reason' => 'Lançado errado'])['data'];
        self::assertSame('300', $this->balance($this->flour['id']));

        $reversal = $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => 'Perda não ocorreu'])['data'];
        $this->assertStatus(201);
        self::assertSame('REVERSAL', $reversal['type']);
        self::assertSame('200', $reversal['quantityDelta']);
        self::assertSame($waste['id'], $reversal['reversalOfId']);
        self::assertSame('500', $this->balance($this->flour['id']));

        $response = $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => 'De novo']);
        $this->assertStatus(409);
        self::assertSame('ALREADY_REVERSED', $response['error']['code']);

        $response = $this->api('POST', "/api/v1/stock/movements/{$reversal['id']}/reversal", ['reason' => 'Estorno do estorno']);
        $this->assertStatus(422);
        self::assertSame('NOT_REVERSIBLE', $response['error']['code']);

        $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => '']);
        $this->assertStatus(422);

        $history = $this->api('GET', '/api/v1/stock/items/INGREDIENT/'.$this->flour['id'].'/movements')['data'];
        $original = array_values(array_filter($history, static fn ($m) => $m['id'] === $waste['id']))[0];
        self::assertTrue($original['reversed']);
        self::assertFalse($original['reversible']);
        self::assertSame(0, bccomp('500', $this->ledgerSum($this->flour['id']), 6));
    }

    public function testReversalCannotMakeStockNegative(): void
    {
        $in = $this->adjust($this->flour['id'], 'ADJUSTMENT_IN', '100')['data'];
        $this->adjust($this->flour['id'], 'WASTE', '80');

        $response = $this->api('POST', "/api/v1/stock/movements/{$in['id']}/reversal", ['reason' => 'Entrada errada']);
        $this->assertStatus(422);
        self::assertSame('INSUFFICIENT_STOCK', $response['error']['code']);
        self::assertSame('20', $this->balance($this->flour['id']));
    }

    public function testProductsUseSaleUnitAndServicesHaveNoStock(): void
    {
        $bread = $this->createProduct(['name' => 'Sonho', 'saleUnitId' => $this->unitId('UN'), 'salePrice' => '7.50']);
        $this->opening($bread['id'], '2', ['unitId' => $this->unitId('DZ')], 'PRODUCT');
        $this->assertStatus(201);
        self::assertSame('24', $this->balance($bread['id'], 'PRODUCT'));

        $service = $this->createProduct(['name' => 'Entrega', 'productionType' => 'SERVICE', 'saleUnitId' => $this->unitId('UN')]);
        $response = $this->opening($service['id'], '1', [], 'PRODUCT');
        $this->assertStatus(422);
        self::assertArrayHasKey('itemId', $response['error']['details']['fields']);

        $listed = array_column($this->api('GET', '/api/v1/stock/balances?itemType=PRODUCT')['data'], 'name');
        self::assertSame(['Sonho'], $listed);
    }

    public function testBalancesListShowsEveryItemWithZeroDefault(): void
    {
        $this->createIngredient('Açúcar');
        $this->opening($this->flour['id'], '2500');

        $response = $this->api('GET', '/api/v1/stock/balances?itemType=INGREDIENT');
        $this->assertStatus(200);
        $rows = array_column($response['data'], 'quantity', 'name');
        self::assertSame(['Açúcar' => '0', 'Farinha de trigo' => '2500'], $rows);
        self::assertSame('G', $response['data'][0]['unit']['code']);
        self::assertSame('Estoque geral', $response['data'][0]['location']['name']);
        self::assertSame(2, $response['meta']['total']);

        self::assertSame(['Farinha de trigo'], array_column($this->api('GET', '/api/v1/stock/balances?itemType=INGREDIENT&q=farin')['data'], 'name'));
    }

    public function testCatalogLocksStockUnitOnceItemHasMovements(): void
    {
        $payload = ['name' => 'Farinha de trigo', 'stockUnitId' => $this->unitId('KG'), 'averageUnitCost' => '4.5', 'minimumStock' => '0'];

        // Sem movimentos: pode trocar.
        $this->api('PUT', '/api/v1/ingredients/'.$this->flour['id'], $payload);
        $this->assertStatus(200);

        $this->opening($this->flour['id'], '10');
        $response = $this->api('PUT', '/api/v1/ingredients/'.$this->flour['id'], [...$payload, 'stockUnitId' => $this->unitId('G')]);
        $this->assertStatus(422);
        self::assertArrayHasKey('stockUnitId', $response['error']['details']['fields']);

        $bread = $this->createProduct(['name' => 'Baguete', 'saleUnitId' => $this->unitId('UN')]);
        $this->opening($bread['id'], '5', [], 'PRODUCT');
        $productPayload = ['name' => 'Baguete', 'saleUnitId' => $this->unitId('UN'), 'productionType' => 'MANUFACTURED', 'salePrice' => '9.00'];

        $this->api('PUT', '/api/v1/products/'.$bread['id'], [...$productPayload, 'saleUnitId' => $this->unitId('KG')]);
        $this->assertStatus(422);

        $response = $this->api('PUT', '/api/v1/products/'.$bread['id'], [...$productPayload, 'stockTracked' => false]);
        $this->assertStatus(422);
        self::assertArrayHasKey('stockTracked', $response['error']['details']['fields']);

        $this->adjust($bread['id'], 'ADJUSTMENT_OUT', '5', [], 'PRODUCT');
        $this->api('PUT', '/api/v1/products/'.$bread['id'], [...$productPayload, 'stockTracked' => false]);
        $this->assertStatus(200);
    }

    /**
     * Auditoria do épico 3: datas inexistentes não podem "rolar" para outro dia.
     */
    public function testRejectsImpossibleCalendarDates(): void
    {
        $response = $this->opening($this->flour['id'], '100', ['occurredAt' => '2026-02-31T10:00:00-03:00']);
        $this->assertStatus(422);
        self::assertArrayHasKey('occurredAt', $response['error']['details']['fields']);

        $this->opening($this->flour['id'], '100', ['occurredAt' => '2026-11-01T25:00:00-03:00']);
        $this->assertStatus(422);
    }

    /**
     * Auditoria do épico 3: estorno não devolve estoque a produto sem controle de estoque.
     */
    public function testCannotReverseIntoUntrackedProduct(): void
    {
        $bread = $this->createProduct(['name' => 'Broa', 'saleUnitId' => $this->unitId('UN')]);
        $this->opening($bread['id'], '5', [], 'PRODUCT');
        $waste = $this->adjust($bread['id'], 'WASTE', '5', [], 'PRODUCT')['data'];
        $this->api('PUT', '/api/v1/products/'.$bread['id'], [
            'name' => 'Broa', 'saleUnitId' => $this->unitId('UN'), 'productionType' => 'MANUFACTURED', 'salePrice' => '18.90', 'stockTracked' => false,
        ]);
        $this->assertStatus(200);

        $response = $this->api('POST', "/api/v1/stock/movements/{$waste['id']}/reversal", ['reason' => 'Engano']);
        $this->assertStatus(422);
        self::assertSame('NOT_STOCK_TRACKED', $response['error']['code']);
    }

    public function testUnknownItemAndLocation(): void
    {
        $this->opening('0199a000-0000-7000-8000-0000000000ff', '1');
        $this->assertStatus(422);

        $this->api('GET', '/api/v1/stock/items/INGREDIENT/0199a000-0000-7000-8000-0000000000ff');
        $this->assertStatus(404);

        $response = $this->opening($this->flour['id'], '1', ['locationId' => '0199a000-0000-7000-8000-0000000000ff']);
        $this->assertStatus(422);
        self::assertArrayHasKey('locationId', $response['error']['details']['fields']);

        $this->api('POST', '/api/v1/stock/movements/0199a000-0000-7000-8000-0000000000ff/reversal', ['reason' => 'x']);
        $this->assertStatus(404);
    }
}
