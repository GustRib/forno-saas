<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Invariantes garantidas pelo PostgreSQL mesmo se a aplicação for contornada
 * (DECISIONS D-13): ledger append-only, saldo nunca negativo, unidade única
 * por item, estorno espelhado e único, sinal coerente, motivo obrigatório.
 */
final class LedgerDatabaseInvariantsTest extends InventoryTestCase
{
    private Connection $db;

    /** @var array<string, string> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTenant('Padaria Banco', 'dono@banco.test');
        $flour = $this->createIngredient();
        $movement = $this->opening($flour['id'], '100')['data'];

        $this->db = static::getContainer()->get('doctrine.dbal.default_connection');
        $tenant = (string) $this->db->fetchOne("SELECT id FROM tenants WHERE name = 'Padaria Banco'");
        $this->ids = [
            'tenant' => $tenant,
            'flour' => $flour['id'],
            'movement' => $movement['id'],
            'g' => $this->unitId('G'),
            'kg' => $this->unitId('KG'),
            'location' => (string) $this->db->fetchOne('SELECT id FROM stock_locations WHERE tenant_id = ?', [$tenant]),
            'user' => (string) $this->db->fetchOne("SELECT id FROM users WHERE email = 'dono@banco.test'"),
        ];
    }

    public function testMovementsCannotBeUpdated(): void
    {
        $this->expectSqlState('23001', fn () => $this->db->executeStatement('UPDATE stock_movements SET quantity_delta = 999 WHERE id = ?', [$this->ids['movement']]));
    }

    public function testMovementsCannotBeDeleted(): void
    {
        $this->expectSqlState('23001', fn () => $this->db->executeStatement('DELETE FROM stock_movements WHERE id = ?', [$this->ids['movement']]));
    }

    public function testBalanceCannotGoNegative(): void
    {
        $this->expectSqlState('23514', fn () => $this->insert(['movement_type' => 'WASTE', 'quantity_delta' => '-100.000001']));
        self::assertSame('100.000000', $this->storedBalance());
    }

    public function testEveryInsertUpdatesBalance(): void
    {
        $this->insert(['movement_type' => 'ADJUSTMENT_IN', 'quantity_delta' => '0.000001']);
        $this->insert(['movement_type' => 'WASTE', 'quantity_delta' => '-50']);

        self::assertSame('50.000001', $this->storedBalance());
        self::assertSame('50.000001', (string) $this->db->fetchOne('SELECT SUM(quantity_delta) FROM stock_movements WHERE ingredient_id = ?', [$this->ids['flour']]));
    }

    public function testMovementUnitMustMatchStockUnit(): void
    {
        $this->expectSqlState('23514', fn () => $this->insert(['movement_type' => 'ADJUSTMENT_IN', 'quantity_delta' => '1', 'unit_id' => $this->ids['kg']]));
    }

    public function testSignMustMatchType(): void
    {
        $this->expectSqlState('23514', fn () => $this->insert(['movement_type' => 'WASTE', 'quantity_delta' => '10']));
    }

    public function testReasonRequiredForAdjustments(): void
    {
        $this->expectSqlState('23514', fn () => $this->insert(['movement_type' => 'ADJUSTMENT_IN', 'quantity_delta' => '1', 'reason' => ' ']));
    }

    public function testReversalMustMirrorOriginal(): void
    {
        $this->expectSqlState('23514', fn () => $this->insert([
            'movement_type' => 'REVERSAL', 'quantity_delta' => '-50', 'reversal_of_id' => $this->ids['movement'], 'reason' => 'meio estorno',
        ]));
    }

    public function testMovementCanOnlyBeReversedOnce(): void
    {
        $this->insert(['movement_type' => 'ADJUSTMENT_IN', 'quantity_delta' => '10']);
        $this->insert(['movement_type' => 'REVERSAL', 'quantity_delta' => '-100', 'reversal_of_id' => $this->ids['movement'], 'reason' => 'estorno']);

        $this->expectSqlState('23505', fn () => $this->insert([
            'id' => '0199a000-0000-7000-8000-0000000000c2',
            'movement_type' => 'REVERSAL', 'quantity_delta' => '-100', 'reversal_of_id' => $this->ids['movement'], 'reason' => 'estorno 2',
        ]));
    }

    /**
     * Auditoria do épico 3: o saldo só pode mudar pelo ledger.
     */
    public function testBalancesCannotBeWrittenDirectly(): void
    {
        $this->expectSqlState('23001', fn () => $this->db->executeStatement('UPDATE stock_balances SET quantity = 999 WHERE ingredient_id = ?', [$this->ids['flour']]));
        $this->expectSqlState('23001', fn () => $this->db->executeStatement('DELETE FROM stock_balances WHERE ingredient_id = ?', [$this->ids['flour']]));
        // Só a linha "vazia" (saldo 0) pode ser criada fora do trigger — é o que
        // StockRepository::lockBalance faz antes de bloquear.
        $this->expectSqlState('23001', fn () => $this->db->executeStatement(
            'INSERT INTO stock_balances (tenant_id, ingredient_id, stock_location_id, unit_id, quantity) VALUES (?, ?, ?, ?, 50)',
            [$this->ids['tenant'], $this->ids['flour'], $this->ids['location'], $this->ids['g']],
        ));
        self::assertSame('100.000000', $this->storedBalance());
    }

    public function testOnlyOneOpeningBalancePerItemAndLocation(): void
    {
        $this->expectSqlState('23505', fn () => $this->insert(['movement_type' => 'OPENING_BALANCE', 'quantity_delta' => '5']));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function insert(array $overrides): void
    {
        static $n = 0;
        $this->db->insert('stock_movements', [
            'id' => \sprintf('0199a000-0000-7000-8000-%012d', ++$n + random_int(1000, 999999)),
            'tenant_id' => $this->ids['tenant'],
            'ingredient_id' => $this->ids['flour'],
            'stock_location_id' => $this->ids['location'],
            'unit_id' => $this->ids['g'],
            'source_type' => 'MANUAL',
            'reason' => 'teste',
            'occurred_at' => '2026-11-25 10:00:00+00',
            'created_by' => $this->ids['user'],
            ...$overrides,
        ]);
    }

    private function storedBalance(): string
    {
        return (string) $this->db->fetchOne('SELECT quantity FROM stock_balances WHERE ingredient_id = ?', [$this->ids['flour']]);
    }

    private function expectSqlState(string $state, callable $statement): void
    {
        // Savepoint: a falha não invalida a transação do teste (DAMA).
        $this->db->beginTransaction();
        try {
            $statement();
            self::fail("Esperava SQLSTATE {$state}.");
        } catch (DriverException $e) {
            self::assertSame($state, $e->getSQLState(), $e->getMessage());
        } finally {
            $this->db->rollBack();
        }
    }
}
