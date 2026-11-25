<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recipe;

use Doctrine\DBAL\Exception\DriverException;

/**
 * Isolamento de tenant, permissões e invariantes de versionamento no banco.
 */
final class RecipeSecurityAndIntegrityTest extends RecipeTestCase
{
    public function testTenantIsolation(): void
    {
        $this->setUpBakery('Padaria A', 'dono@receita-a.test');
        $recipe = $this->createRecipe();
        $idsA = $this->ids;

        $this->setUpBakery('Padaria B', 'dono@receita-b.test');

        foreach (['GET' => "/api/v1/recipes/{$recipe['id']}", 'POST' => "/api/v1/recipes/{$recipe['id']}/activate"] as $method => $uri) {
            $this->api($method, $uri);
            $this->assertStatus(404);
        }
        $this->api('PUT', "/api/v1/recipes/{$recipe['id']}", $this->examplePayload());
        $this->assertStatus(404);
        $this->api('DELETE', "/api/v1/recipes/{$recipe['id']}");
        $this->assertStatus(404);
        $this->api('POST', "/api/v1/recipes/{$recipe['id']}/new-version");
        $this->assertStatus(404);
        $this->api('GET', "/api/v1/recipes/{$recipe['id']}/cost-snapshots");
        $this->assertStatus(404);
        self::assertSame(0, $this->api('GET', '/api/v1/recipes')['meta']['total']);

        // Produto e ingrediente de A não podem ser usados por B.
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['productId' => $idsA['bread']]));
        $this->assertStatus(422);
        self::assertArrayHasKey('productId', $response['error']['details']['fields']);

        $items = $this->examplePayload()['items'];
        $items[0]['ingredientId'] = $idsA['flour'];
        $response = $this->api('POST', '/api/v1/recipes', $this->examplePayload(['items' => $items]));
        $this->assertStatus(422);
        self::assertArrayHasKey('items[0][ingredientId]', $response['error']['details']['fields']);
    }

    public function testDatabaseRejectsCrossTenantRecipeItem(): void
    {
        $this->setUpBakery('Padaria A', 'dono@fk-receita-a.test');
        $flourA = $this->ids['flour'];
        $this->setUpBakery('Padaria B', 'dono@fk-receita-b.test');
        $recipe = $this->createRecipe();

        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $tenantB = (string) $db->fetchOne("SELECT id FROM tenants WHERE name = 'Padaria B'");

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $db->insert('recipe_items', [
            'id' => '0199a000-0000-7000-8000-0000000000d1', 'tenant_id' => $tenantB, 'recipe_id' => $recipe['id'],
            'ingredient_id' => $flourA, 'quantity' => '1', 'unit_id' => $this->unitId('G'),
        ]);
    }

    public function testPermissions(): void
    {
        $this->setUpBakery('Padaria Papéis', 'dono@receita-papeis.test');
        $recipe = $this->createRecipe();
        $this->addMember('caixa@receita-papeis.test', 'CASHIER');
        $this->addMember('producao@receita-papeis.test', 'PRODUCTION');
        $this->addMember('gerente@receita-papeis.test', 'MANAGER');

        // Caixa: "não altera receitas, custos" — e não as consulta.
        $this->login('caixa@receita-papeis.test');
        $this->api('GET', '/api/v1/recipes');
        $this->assertStatus(403);
        $this->api('GET', '/api/v1/ingredients/'.$this->ids['flour'].'/cost-history');
        $this->assertStatus(200); // histórico de custo faz parte do cadastro (catalog.read)

        // Produção: "consulta receitas", não edita.
        $this->login('producao@receita-papeis.test');
        $this->api('GET', "/api/v1/recipes/{$recipe['id']}");
        $this->assertStatus(200);
        $this->api('PUT', "/api/v1/recipes/{$recipe['id']}", $this->examplePayload());
        $this->assertStatus(403);
        $this->api('POST', "/api/v1/recipes/{$recipe['id']}/activate");
        $this->assertStatus(403);
        $this->api('POST', '/api/v1/recipes', $this->examplePayload());
        $this->assertStatus(403);

        // Gerente: gerencia a operação.
        $this->login('gerente@receita-papeis.test');
        $this->api('POST', "/api/v1/recipes/{$recipe['id']}/activate");
        $this->assertStatus(200);
        $this->api('POST', "/api/v1/recipes/{$recipe['id']}/cost-snapshots");
        $this->assertStatus(201);
    }

    public function testDatabaseEnforcesVersionImmutability(): void
    {
        $this->setUpBakery('Padaria Banco', 'dono@receita-banco.test');
        $recipe = $this->createRecipe();
        $this->api('POST', "/api/v1/recipes/{$recipe['id']}/activate");
        $db = static::getContainer()->get('doctrine.dbal.default_connection');

        $attempts = [
            'UPDATE recipes SET expected_yield_qty = 99 WHERE id = ?',
            "UPDATE recipes SET status = 'DRAFT', effective_from = NULL, activated_at = NULL WHERE id = ?",
            'DELETE FROM recipes WHERE id = ?',
            'UPDATE recipe_items SET quantity = 1 WHERE recipe_id = ?',
            'DELETE FROM recipe_items WHERE recipe_id = ?',
            'DELETE FROM recipe_additional_costs WHERE recipe_id = ?',
            'UPDATE recipe_cost_snapshots SET total_cost = 0 WHERE recipe_id = ?',
        ];
        foreach ($attempts as $sql) {
            $db->beginTransaction();
            try {
                $db->executeStatement($sql, [$recipe['id']]);
                self::fail("Deveria ser recusado: {$sql}");
            } catch (DriverException $e) {
                self::assertSame('23001', $e->getSQLState(), $sql);
            } finally {
                $db->rollBack();
            }
        }

        // Duas versões ativas para o mesmo produto: índice único.
        $v2 = $this->api('POST', "/api/v1/recipes/{$recipe['id']}/new-version")['data'];
        $db->beginTransaction();
        try {
            $db->executeStatement("UPDATE recipes SET status = 'ACTIVE', effective_from = CURRENT_DATE, activated_at = NOW() WHERE id = ?", [$v2['id']]);
            self::fail('Duas receitas ativas deveriam ser recusadas.');
        } catch (DriverException $e) {
            self::assertSame('23505', $e->getSQLState());
        } finally {
            $db->rollBack();
        }

        // Arquivar a ativa é a única transição permitida.
        $db->beginTransaction();
        $db->executeStatement("UPDATE recipes SET status = 'ARCHIVED', archived_at = NOW() WHERE id = ?", [$recipe['id']]);
        self::assertSame('ARCHIVED', $db->fetchOne('SELECT status FROM recipes WHERE id = ?', [$recipe['id']]));
        $db->rollBack();
    }
}
