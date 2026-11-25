<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recipe;

/**
 * FNO-040 (receita vinculada a produto), FNO-041 (ingredientes, quantidades,
 * unidades), FNO-042 (custo por lote e unidade), FNO-043 (versões), FNO-044
 * (custo adicional e histórico de custo).
 */
final class RecipeTest extends RecipeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBakery('Padaria Receitas', 'dono@receitas.test');
    }

    /**
     * Aceite do BACKLOG: 10 kg de farinha, 6 L de água, 200 g de fermento e
     * 200 g de sal calcula o custo conforme custos de estoque e rendimento.
     */
    public function testBacklogAcceptanceExample(): void
    {
        $recipe = $this->createRecipe();

        self::assertSame('DRAFT', $recipe['status']);
        self::assertSame(1, $recipe['version']);
        self::assertSame('Pão francês', $recipe['product']['name']);
        self::assertSame(['Farinha de trigo', 'Água', 'Fermento', 'Sal'], array_column($recipe['items'], 'ingredientName'));
        self::assertSame('Sal refinado', $recipe['items'][3]['notes']);

        $cost = $recipe['cost'];
        self::assertTrue($cost['complete']);
        self::assertSame('53.43', $cost['ingredientsCost']);   // 45,00 + 0,03 + 8,00 + 0,40
        self::assertSame('7.50', $cost['additionalCost']);
        self::assertSame('60.93', $cost['totalCost']);
        self::assertSame('3.584118', $cost['costPerYieldUnit']); // por kg produzido
        self::assertSame('3.584118', $cost['costPerSaleUnit']);  // vendido por kg
        self::assertSame(['10000', '6', '200', '0.2'], array_column($cost['lines'], 'stockQuantity'));
        self::assertSame(['MANUAL', 'MANUAL', 'MANUAL', 'MANUAL'], array_column($cost['lines'], 'costSource'));
        self::assertNotNull($cost['lines'][0]['costChangedAt']);
    }

    public function testCostFollowsStockCostPolicy(): void
    {
        // Custo da farinha passa a vir do estoque (WAC-1): 0,004 e 0,005 → 0,0045.
        $this->api('PUT', '/api/v1/ingredients/'.$this->ids['flour'], ['name' => 'Farinha de trigo', 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0.001', 'minimumStock' => '0']);
        $this->opening($this->ids['flour'], '25', ['unitId' => $this->unitId('KG'), 'unitCost' => '0.004']);
        $this->adjust($this->ids['flour'], 'ADJUSTMENT_IN', '25', ['unitId' => $this->unitId('KG'), 'unitCost' => '0.005']);

        $recipe = $this->createRecipe();
        self::assertSame('53.43', $recipe['cost']['ingredientsCost']);
        self::assertSame('STOCK_MOVEMENT', $recipe['cost']['lines'][0]['costSource']);
        self::assertSame('0.0045', $recipe['cost']['lines'][0]['unitCost']);
    }

    public function testVersioningLifecycle(): void
    {
        $v1 = $this->createRecipe();

        // Só um rascunho por produto.
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload());
        $this->assertStatus(409);
        self::assertSame('DRAFT_EXISTS', $response['error']['code']);

        // Rascunho é editável.
        $edited = $this->api('PUT', "/api/v1/recipes/{$v1['id']}", $this->examplePayload(['expectedYieldQty' => '16.5']))['data'];
        $this->assertStatus(200);
        self::assertSame('16.5', $edited['expectedYieldQty']);

        $active = $this->api('POST', "/api/v1/recipes/{$v1['id']}/activate")['data'];
        $this->assertStatus(200);
        self::assertSame('ACTIVE', $active['status']);
        self::assertNotNull($active['effectiveFrom']);

        // Versão publicada é imutável pela API…
        $response = $this->api('PUT', "/api/v1/recipes/{$v1['id']}", $this->examplePayload(['expectedYieldQty' => '20']));
        $this->assertStatus(409);
        self::assertSame('RECIPE_NOT_DRAFT', $response['error']['code']);
        $this->api('DELETE', "/api/v1/recipes/{$v1['id']}");
        $this->assertStatus(409);

        // Nova versão copia o conteúdo para um rascunho v2.
        $v2 = $this->api('POST', "/api/v1/recipes/{$v1['id']}/new-version")['data'];
        $this->assertStatus(201);
        self::assertSame(2, $v2['version']);
        self::assertSame('DRAFT', $v2['status']);
        self::assertSame('16.5', $v2['expectedYieldQty']);
        self::assertCount(4, $v2['items']);

        // Alteração relevante na v2: menos sal, sem custo adicional.
        $items = $this->examplePayload()['items'];
        $items[3]['quantity'] = '180';
        $this->api('PUT', "/api/v1/recipes/{$v2['id']}", $this->examplePayload(['items' => $items, 'additionalCosts' => [], 'expectedYieldQty' => '16.5']));
        $this->assertStatus(200);

        $this->api('POST', "/api/v1/recipes/{$v2['id']}/activate");
        $this->assertStatus(200);

        $old = $this->api('GET', "/api/v1/recipes/{$v1['id']}")['data'];
        self::assertSame('ARCHIVED', $old['status']);
        self::assertSame('200', $old['items'][3]['quantity'], 'versão arquivada preserva o conteúdo');
        self::assertSame([2, 1], array_column($old['versions'], 'version'));
        self::assertSame(['ACTIVE', 'ARCHIVED'], array_column($old['versions'], 'status'));

        $actives = $this->api('GET', '/api/v1/recipes?status=ACTIVE&productId='.$this->ids['bread'])['data'];
        self::assertSame([2], array_column($actives, 'version'));

        // Arquivada também não volta a ser editável nem pode gerar rascunho enquanto outro existe.
        $v3 = $this->api('POST', "/api/v1/recipes/{$v1['id']}/new-version")['data'];
        self::assertSame(3, $v3['version']);
        $this->api('POST', "/api/v1/recipes/{$v1['id']}/new-version");
        $this->assertStatus(409);

        // Rascunho pode ser descartado.
        $this->api('DELETE', "/api/v1/recipes/{$v3['id']}");
        $this->assertStatus(204);
        $this->api('GET', "/api/v1/recipes/{$v3['id']}");
        $this->assertStatus(404);
    }

    public function testActivationRecordsImmutableCostSnapshot(): void
    {
        $recipe = $this->createRecipe();
        $this->api('POST', "/api/v1/recipes/{$recipe['id']}/activate");

        $snapshots = $this->api('GET', "/api/v1/recipes/{$recipe['id']}/cost-snapshots")['data'];
        self::assertCount(1, $snapshots);
        self::assertSame('ACTIVATION', $snapshots[0]['reason']);
        self::assertSame('WAC-1', $snapshots[0]['costPolicy']);
        self::assertSame('60.93', $snapshots[0]['totalCost']);
        self::assertSame('0.0045', $snapshots[0]['details']['lines'][0]['unitCost']);

        // Custo do ingrediente muda: estimativa ao vivo muda, snapshot não.
        $this->api('PUT', '/api/v1/ingredients/'.$this->ids['flour'], ['name' => 'Farinha de trigo', 'stockUnitId' => $this->unitId('G'), 'averageUnitCost' => '0.005', 'minimumStock' => '0']);
        $live = $this->api('GET', "/api/v1/recipes/{$recipe['id']}")['data']['cost'];
        self::assertSame('65.93', $live['totalCost']);

        $manual = $this->api('POST', "/api/v1/recipes/{$recipe['id']}/cost-snapshots")['data'];
        $this->assertStatus(201);
        self::assertSame('MANUAL', $manual['reason']);
        self::assertSame('65.93', $manual['totalCost']);

        $snapshots = $this->api('GET', "/api/v1/recipes/{$recipe['id']}/cost-snapshots")['data'];
        self::assertSame(['65.93', '60.93'], array_column($snapshots, 'totalCost'));
    }

    public function testSnapshotOnlyForActiveVersion(): void
    {
        $draft = $this->createRecipe();
        $response = $this->api('POST', "/api/v1/recipes/{$draft['id']}/cost-snapshots");
        $this->assertStatus(422);
        self::assertSame('RECIPE_NOT_ACTIVE', $response['error']['code']);
    }

    public function testValidationRules(): void
    {
        $items = $this->examplePayload()['items'];

        // Massa em volume: sem densidade não há conversão.
        $bad = $items;
        $bad[0]['unitId'] = $this->unitId('L');
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['items' => $bad]));
        $this->assertStatus(422);
        self::assertArrayHasKey('items[0][unitId]', $response['error']['details']['fields']);

        // Ingrediente repetido.
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['items' => [...$items, $items[0]]]));
        $this->assertStatus(422);
        self::assertArrayHasKey('items[4][ingredientId]', $response['error']['details']['fields']);

        // Rendimento fora da dimensão da unidade de venda (pão vendido por kg).
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['yieldUnitId' => $this->unitId('UN')]));
        $this->assertStatus(422);
        self::assertArrayHasKey('yieldUnitId', $response['error']['details']['fields']);

        // Formatos: quantidade numérica JSON, quantidade zero, custo adicional inválido.
        $numeric = $items;
        $numeric[0]['quantity'] = 10;
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['items' => $numeric]));
        $this->assertStatus(422);
        self::assertArrayHasKey('items[0][quantity]', $response['error']['details']['fields']);

        $zero = $items;
        $zero[0]['quantity'] = '0';
        $this->api('POST', '/api/v1/recipes', $this->examplePayload(['items' => $zero]));
        $this->assertStatus(422);

        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['additionalCosts' => [['description' => '', 'amount' => '1,50']]]));
        $this->assertStatus(422);
        self::assertArrayHasKey('additionalCosts[0][description]', $response['error']['details']['fields']);
        self::assertArrayHasKey('additionalCosts[0][amount]', $response['error']['details']['fields']);

        $this->api('POST', '/api/v1/recipes', $this->examplePayload(['expectedYieldQty' => '0']));
        $this->assertStatus(422);

        // Só produto fabricado aqui.
        $resale = $this->createProduct(['name' => 'Refrigerante', 'productionType' => 'RESALE', 'saleUnitId' => $this->unitId('UN')]);
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['productId' => $resale['id'], 'yieldUnitId' => $this->unitId('UN')]));
        $this->assertStatus(422);
        self::assertArrayHasKey('productId', $response['error']['details']['fields']);
    }

    public function testEmptyDraftCanBeSavedButNotActivated(): void
    {
        $draft = $this->createRecipe(['items' => [], 'additionalCosts' => []]);
        self::assertFalse($draft['cost']['complete']);
        self::assertSame('NO_ITEMS', $draft['cost']['warnings'][0]['code']);

        $response = $this->api('POST', "/api/v1/recipes/{$draft['id']}/activate");
        $this->assertStatus(422);
        self::assertSame('RECIPE_INCOMPLETE', $response['error']['code']);
    }

    public function testZeroCostIngredientIsFlagged(): void
    {
        $this->api('PUT', '/api/v1/ingredients/'.$this->ids['water'], ['name' => 'Água', 'stockUnitId' => $this->unitId('L'), 'averageUnitCost' => '0', 'minimumStock' => '0']);
        $recipe = $this->createRecipe();

        self::assertTrue($recipe['cost']['complete']);
        self::assertSame('ZERO_COST', $recipe['cost']['warnings'][0]['code']);
        self::assertSame($this->ids['water'], $recipe['cost']['warnings'][0]['ingredientId']);
        self::assertSame('53.40', $recipe['cost']['ingredientsCost']);
    }

    public function testItemsCanBeReplacedAndReorderedInDraft(): void
    {
        $recipe = $this->createRecipe();
        $items = $this->examplePayload()['items'];

        // Remove o sal, inverte a ordem e altera a farinha (mesmo ingrediente atualizado no lugar).
        $new = [$items[2], $items[1], ['ingredientId' => $this->ids['flour'], 'quantity' => '9.5', 'unitId' => $this->unitId('KG')]];
        $updated = $this->api('PUT', "/api/v1/recipes/{$recipe['id']}", $this->examplePayload(['items' => $new]))['data'];

        $this->assertStatus(200);
        self::assertSame(['Fermento', 'Água', 'Farinha de trigo'], array_column($updated['items'], 'ingredientName'));
        self::assertSame('9.5', $updated['items'][2]['quantity']);
    }

    public function testCatalogChangesThatWouldBreakOpenRecipesAreBlocked(): void
    {
        $this->createRecipe();

        // Ingrediente da receita não pode virar volume (farinha em ml).
        $response = $this->api('PUT', '/api/v1/ingredients/'.$this->ids['flour'], ['name' => 'Farinha de trigo', 'stockUnitId' => $this->unitId('ML'), 'averageUnitCost' => '0.0045', 'minimumStock' => '0']);
        $this->assertStatus(422);
        self::assertArrayHasKey('stockUnitId', $response['error']['details']['fields']);

        // Mesma dimensão é permitido (g → kg) e o custo continua correto.
        $this->api('PUT', '/api/v1/ingredients/'.$this->ids['flour'], ['name' => 'Farinha de trigo', 'stockUnitId' => $this->unitId('KG'), 'averageUnitCost' => '4.5', 'minimumStock' => '0']);
        $this->assertStatus(200);

        $product = ['name' => 'Pão francês', 'saleUnitId' => $this->unitId('KG'), 'productionType' => 'MANUFACTURED', 'salePrice' => '18.90'];
        $this->api('PUT', '/api/v1/products/'.$this->ids['bread'], [...$product, 'saleUnitId' => $this->unitId('UN')]);
        $this->assertStatus(422);
        $this->api('PUT', '/api/v1/products/'.$this->ids['bread'], [...$product, 'productionType' => 'RESALE']);
        $this->assertStatus(422);
        $this->api('PUT', '/api/v1/products/'.$this->ids['bread'], [...$product, 'saleUnitId' => $this->unitId('G')]);
        $this->assertStatus(200);

        $recipe = $this->api('GET', '/api/v1/recipes?productId='.$this->ids['bread'])['data'][0];
        $detail = $this->api('GET', '/api/v1/recipes/'.$recipe['id'])['data'];
        self::assertSame('53.43', $detail['cost']['ingredientsCost']);
        self::assertSame('0.003584', $detail['cost']['costPerSaleUnit']); // agora por grama
    }
}
