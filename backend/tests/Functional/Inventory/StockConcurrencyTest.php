<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Aceite do épico 3: "tentativas concorrentes não geram saldo negativo".
 *
 * Usa duas conexões reais (fora da transação do DAMA) e dados efetivamente
 * gravados, simulando dois dispositivos lançando saídas ao mesmo tempo.
 */
#[Group('concurrency')]
final class StockConcurrencyTest extends KernelTestCase
{
    private Connection $a;
    private Connection $b;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $container */
        $container = static::getContainer()->get('doctrine.dbal.default_connection');
        $params = $container->getParams();
        $this->a = DriverManager::getConnection($params);
        $this->b = DriverManager::getConnection($params);

        $this->ids = [
            'tenant' => Uuid::v7()->toRfc4122(),
            'user' => Uuid::v7()->toRfc4122(),
            'unit' => Uuid::v7()->toRfc4122(),
            'ingredient' => Uuid::v7()->toRfc4122(),
            'location' => Uuid::v7()->toRfc4122(),
        ];
        $suffix = substr($this->ids['tenant'], -12);
        $this->a->insert('tenants', ['id' => $this->ids['tenant'], 'name' => 'Concorrência '.$suffix]);
        $this->a->insert('users', ['id' => $this->ids['user'], 'name' => 'Teste', 'email' => "conc-{$suffix}@forno.test", 'password_hash' => 'x']);
        $this->a->insert('units_of_measure', ['id' => $this->ids['unit'], 'tenant_id' => $this->ids['tenant'], 'code' => 'G', 'name' => 'Grama', 'dimension' => 'MASS', 'base_factor' => '1', 'is_base' => 'true']);
        $this->a->insert('ingredients', ['id' => $this->ids['ingredient'], 'tenant_id' => $this->ids['tenant'], 'name' => 'Farinha', 'stock_unit_id' => $this->ids['unit']]);
        $this->a->insert('stock_locations', ['id' => $this->ids['location'], 'tenant_id' => $this->ids['tenant'], 'name' => 'Estoque geral', 'is_default' => 'true']);
        $this->movement($this->a, 'OPENING_BALANCE', '10');
    }

    protected function tearDown(): void
    {
        // O ledger é append-only; para limpar dados de teste, desliga os triggers
        // só nesta sessão (requer superusuário, o que o ambiente de teste é).
        $this->a->executeStatement("SET session_replication_role = 'replica'");
        foreach (['ingredient_cost_history', 'stock_movements', 'stock_balances', 'stock_locations', 'ingredients', 'units_of_measure'] as $table) {
            $this->a->executeStatement("DELETE FROM {$table} WHERE tenant_id = ?", [$this->ids['tenant']]);
        }
        $this->a->executeStatement('DELETE FROM tenants WHERE id = ?', [$this->ids['tenant']]);
        $this->a->executeStatement('DELETE FROM users WHERE id = ?', [$this->ids['user']]);
        $this->a->executeStatement("SET session_replication_role = 'origin'");
        $this->a->close();
        $this->b->close();
        parent::tearDown();
    }

    public function testConcurrentOutflowsAreSerializedAndCannotOversell(): void
    {
        // Dispositivo A tira 6 g (saldo 10 → 4) e ainda não confirmou.
        $this->a->beginTransaction();
        $this->lockBalance($this->a);
        $this->movement($this->a, 'WASTE', '-6');

        // Dispositivo B tenta tirar 6 g ao mesmo tempo: fica esperando o lock de A.
        $this->b->beginTransaction();
        $this->b->executeStatement("SET LOCAL lock_timeout = '300ms'");
        try {
            $this->lockBalance($this->b);
            self::fail('B deveria esperar o bloqueio de A.');
        } catch (DriverException $e) {
            self::assertSame('55P03', $e->getSQLState(), 'lock_not_available');
        }
        $this->b->rollBack();

        $this->a->commit();

        // B tenta de novo após A confirmar: agora vê saldo 4 e o banco recusa.
        $this->b->beginTransaction();
        self::assertSame('4.000000', $this->lockBalance($this->b));
        try {
            $this->movement($this->b, 'WASTE', '-6');
            self::fail('Saída maior que o saldo deveria ser recusada.');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState());
        }
        $this->b->rollBack();

        self::assertSame('4.000000', $this->balance());
        self::assertSame('4.000000', (string) $this->a->fetchOne('SELECT SUM(quantity_delta) FROM stock_movements WHERE ingredient_id = ?', [$this->ids['ingredient']]));
    }

    public function testConcurrentInsertsWithoutApplicationLockStillCannotGoNegative(): void
    {
        // Mesmo sem o SELECT … FOR UPDATE da aplicação, o UPSERT do trigger
        // bloqueia a linha de saldo e o CHECK impede o negativo.
        $this->a->beginTransaction();
        $this->movement($this->a, 'WASTE', '-7');

        $this->b->beginTransaction();
        $this->b->executeStatement("SET LOCAL lock_timeout = '300ms'");
        try {
            $this->movement($this->b, 'WASTE', '-7');
            self::fail('B deveria esperar A.');
        } catch (DriverException $e) {
            self::assertSame('55P03', $e->getSQLState());
        }
        $this->b->rollBack();
        $this->a->commit();

        $this->b->beginTransaction();
        try {
            $this->movement($this->b, 'WASTE', '-7');
            self::fail('Deveria recusar saldo negativo.');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState());
        }
        $this->b->rollBack();

        self::assertSame('3.000000', $this->balance());
    }

    /**
     * WAC-1: o custo médio usa o saldo total do ingrediente. StockLedger e a
     * edição de custo no cadastro bloqueiam a linha do ingrediente antes de
     * ler esse saldo; uma segunda operação espera e então lê o custo já gravado.
     */
    public function testCostUpdatesOnSameIngredientAreSerialized(): void
    {
        $lockSql = 'SELECT average_unit_cost FROM ingredients WHERE tenant_id = ? AND id = ? FOR UPDATE';
        $params = [$this->ids['tenant'], $this->ids['ingredient']];

        $this->a->beginTransaction();
        $this->a->fetchOne($lockSql, $params);
        $this->a->executeStatement('UPDATE ingredients SET average_unit_cost = 0.0045 WHERE id = ?', [$this->ids['ingredient']]);

        $this->b->beginTransaction();
        $this->b->executeStatement("SET LOCAL lock_timeout = '300ms'");
        try {
            $this->b->fetchOne($lockSql, $params);
            self::fail('B deveria esperar o bloqueio de A.');
        } catch (DriverException $e) {
            self::assertSame('55P03', $e->getSQLState());
        }
        $this->b->rollBack();
        $this->a->commit();

        $this->b->beginTransaction();
        self::assertSame('0.004500', (string) $this->b->fetchOne($lockSql, $params), 'B lê o custo já atualizado por A');
        $this->b->rollBack();
    }

    private function lockBalance(Connection $c): string
    {
        return (string) $c->fetchOne(
            'SELECT quantity FROM stock_balances WHERE tenant_id = ? AND ingredient_id = ? AND stock_location_id = ? FOR UPDATE',
            [$this->ids['tenant'], $this->ids['ingredient'], $this->ids['location']],
        );
    }

    private function movement(Connection $c, string $type, string $delta): void
    {
        $c->insert('stock_movements', [
            'id' => Uuid::v7()->toRfc4122(),
            'tenant_id' => $this->ids['tenant'],
            'ingredient_id' => $this->ids['ingredient'],
            'stock_location_id' => $this->ids['location'],
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'unit_id' => $this->ids['unit'],
            'source_type' => 'MANUAL',
            'reason' => 'concorrência',
            'occurred_at' => '2026-11-25 10:00:00+00',
            'created_by' => $this->ids['user'],
        ]);
    }

    private function balance(): string
    {
        return (string) $this->a->fetchOne('SELECT quantity FROM stock_balances WHERE ingredient_id = ?', [$this->ids['ingredient']]);
    }
}
