<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Épico 3 — estoque: stock_locations, stock_movements (ledger) e
 * stock_balances (saldo materializado, mantido pelo banco).
 *
 * Invariantes garantidas pelo PostgreSQL (DECISIONS D-13):
 *  - movimentos são append-only (trigger bloqueia UPDATE/DELETE);
 *  - todo INSERT no ledger atualiza o saldo na mesma transação (trigger);
 *  - saldo nunca negativo (CHECK) — FR-036, sem exceção configurável ainda;
 *  - todos os movimentos de um item/local usam a mesma unidade (unidade de estoque);
 *  - estorno espelha exatamente o movimento original e só pode existir um;
 *  - sinal da quantidade coerente com o tipo; motivo obrigatório em perdas/ajustes.
 *
 * inventory_lot_id (DATABASE §3.14) fica para FNO-034 (lotes e validade, P1).
 */
final class Version20261126000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estoque: locais, ledger de movimentações imutável e saldos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_locations (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                name VARCHAR(100) NOT NULL,
                is_default BOOLEAN NOT NULL DEFAULT FALSE,
                CONSTRAINT uq_stock_locations_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT uq_stock_locations_tenant_name UNIQUE (tenant_id, name)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_stock_locations_default ON stock_locations(tenant_id) WHERE is_default');

        // Local padrão para estabelecimentos que já existiam (DATABASE §3.12).
        $this->addSql(<<<'SQL'
            INSERT INTO stock_locations (id, tenant_id, name, is_default)
            SELECT gen_random_uuid(), t.id, 'Estoque geral', TRUE
            FROM tenants t
            WHERE NOT EXISTS (SELECT 1 FROM stock_locations l WHERE l.tenant_id = t.id AND l.is_default)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE stock_movements (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                ingredient_id UUID,
                product_id UUID,
                stock_location_id UUID NOT NULL,
                movement_type VARCHAR(32) NOT NULL CHECK (movement_type IN (
                    'OPENING_BALANCE', 'PURCHASE_RECEIPT', 'PRODUCTION_CONSUMPTION',
                    'PRODUCTION_OUTPUT', 'SALE', 'WASTE', 'ADJUSTMENT_IN',
                    'ADJUSTMENT_OUT', 'RETURN_IN', 'REVERSAL'
                )),
                quantity_delta NUMERIC(18,6) NOT NULL CHECK (quantity_delta <> 0),
                unit_id UUID NOT NULL,
                unit_cost NUMERIC(18,6) CHECK (unit_cost >= 0),
                source_type VARCHAR(40) NOT NULL,
                source_id UUID,
                reversal_of_id UUID,
                reason VARCHAR(255),
                occurred_at TIMESTAMPTZ NOT NULL,
                created_by UUID NOT NULL REFERENCES users(id),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_stock_movements_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_stock_movements_ingredient FOREIGN KEY (tenant_id, ingredient_id) REFERENCES ingredients(tenant_id, id),
                CONSTRAINT fk_stock_movements_product FOREIGN KEY (tenant_id, product_id) REFERENCES products(tenant_id, id),
                CONSTRAINT fk_stock_movements_location FOREIGN KEY (tenant_id, stock_location_id) REFERENCES stock_locations(tenant_id, id),
                CONSTRAINT fk_stock_movements_unit FOREIGN KEY (tenant_id, unit_id) REFERENCES units_of_measure(tenant_id, id),
                CONSTRAINT fk_stock_movements_reversal FOREIGN KEY (tenant_id, reversal_of_id) REFERENCES stock_movements(tenant_id, id),
                CONSTRAINT ck_stock_movements_one_item CHECK (num_nonnulls(ingredient_id, product_id) = 1),
                CONSTRAINT ck_stock_movements_sign CHECK (
                    (movement_type IN ('OPENING_BALANCE', 'PURCHASE_RECEIPT', 'PRODUCTION_OUTPUT', 'ADJUSTMENT_IN', 'RETURN_IN') AND quantity_delta > 0)
                    OR (movement_type IN ('PRODUCTION_CONSUMPTION', 'SALE', 'WASTE', 'ADJUSTMENT_OUT') AND quantity_delta < 0)
                    OR movement_type = 'REVERSAL'
                ),
                CONSTRAINT ck_stock_movements_reversal CHECK ((movement_type = 'REVERSAL') = (reversal_of_id IS NOT NULL)),
                CONSTRAINT ck_stock_movements_reason CHECK (
                    movement_type NOT IN ('WASTE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'RETURN_IN', 'REVERSAL')
                    OR (reason IS NOT NULL AND btrim(reason) <> '')
                )
            )
            SQL);
        $this->addSql('CREATE INDEX idx_stock_movements_ingredient_date ON stock_movements(tenant_id, ingredient_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_stock_movements_product_date ON stock_movements(tenant_id, product_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_stock_movements_source ON stock_movements(tenant_id, source_type, source_id)');
        $this->addSql('CREATE INDEX idx_stock_movements_tenant_date ON stock_movements(tenant_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_stock_movements_location ON stock_movements(tenant_id, stock_location_id)');
        $this->addSql('CREATE INDEX idx_stock_movements_unit ON stock_movements(tenant_id, unit_id)');
        $this->addSql('CREATE INDEX idx_stock_movements_created_by ON stock_movements(created_by)');
        // Um movimento só pode ser estornado uma vez.
        $this->addSql('CREATE UNIQUE INDEX uq_stock_movements_reversal_of ON stock_movements(reversal_of_id) WHERE reversal_of_id IS NOT NULL');
        // Saldo inicial: no máximo um por item e local.
        $this->addSql("CREATE UNIQUE INDEX uq_stock_movements_opening_ingredient ON stock_movements(tenant_id, stock_location_id, ingredient_id) WHERE movement_type = 'OPENING_BALANCE' AND ingredient_id IS NOT NULL");
        $this->addSql("CREATE UNIQUE INDEX uq_stock_movements_opening_product ON stock_movements(tenant_id, stock_location_id, product_id) WHERE movement_type = 'OPENING_BALANCE' AND product_id IS NOT NULL");

        $this->addSql(<<<'SQL'
            CREATE TABLE stock_balances (
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                ingredient_id UUID,
                product_id UUID,
                stock_location_id UUID NOT NULL,
                unit_id UUID NOT NULL,
                quantity NUMERIC(18,6) NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_stock_balances_ingredient FOREIGN KEY (tenant_id, ingredient_id) REFERENCES ingredients(tenant_id, id),
                CONSTRAINT fk_stock_balances_product FOREIGN KEY (tenant_id, product_id) REFERENCES products(tenant_id, id),
                CONSTRAINT fk_stock_balances_location FOREIGN KEY (tenant_id, stock_location_id) REFERENCES stock_locations(tenant_id, id),
                CONSTRAINT fk_stock_balances_unit FOREIGN KEY (tenant_id, unit_id) REFERENCES units_of_measure(tenant_id, id),
                CONSTRAINT ck_stock_balances_one_item CHECK (num_nonnulls(ingredient_id, product_id) = 1),
                CONSTRAINT ck_stock_balances_non_negative CHECK (quantity >= 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_stock_balances_ingredient ON stock_balances(tenant_id, stock_location_id, ingredient_id) WHERE ingredient_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_stock_balances_product ON stock_balances(tenant_id, stock_location_id, product_id) WHERE product_id IS NOT NULL');
        $this->addSql('CREATE INDEX idx_stock_balances_ingredient ON stock_balances(tenant_id, ingredient_id)');
        $this->addSql('CREATE INDEX idx_stock_balances_product ON stock_balances(tenant_id, product_id)');
        $this->addSql('CREATE INDEX idx_stock_balances_unit ON stock_balances(tenant_id, unit_id)');

        // Ledger imutável.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION forno_stock_movements_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only; post a REVERSAL instead'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_stock_movements_immutable
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION forno_stock_movements_immutable()
            SQL);

        // Cada movimento atualiza o saldo materializado na mesma transação.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION forno_stock_movements_apply() RETURNS trigger AS $$
            DECLARE
                original stock_movements%ROWTYPE;
                balance_unit UUID;
            BEGIN
                IF NEW.movement_type = 'REVERSAL' THEN
                    SELECT * INTO original FROM stock_movements
                        WHERE tenant_id = NEW.tenant_id AND id = NEW.reversal_of_id;
                    IF original.movement_type = 'REVERSAL'
                        OR original.quantity_delta <> -NEW.quantity_delta
                        OR original.unit_id <> NEW.unit_id
                        OR original.stock_location_id <> NEW.stock_location_id
                        OR original.ingredient_id IS DISTINCT FROM NEW.ingredient_id
                        OR original.product_id IS DISTINCT FROM NEW.product_id THEN
                        RAISE EXCEPTION 'reversal must mirror the original movement'
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'ck_stock_movements_reversal_mirror';
                    END IF;
                END IF;

                -- Garante a linha com saldo 0 e depois soma o delta. (Um UPSERT
                -- direto com o delta falharia: o CHECK >= 0 é avaliado na linha
                -- proposta do INSERT antes de resolver o conflito.) O UPDATE
                -- bloqueia a linha, serializando lançamentos concorrentes.
                IF NEW.ingredient_id IS NOT NULL THEN
                    INSERT INTO stock_balances (tenant_id, ingredient_id, stock_location_id, unit_id, quantity)
                    VALUES (NEW.tenant_id, NEW.ingredient_id, NEW.stock_location_id, NEW.unit_id, 0)
                    ON CONFLICT (tenant_id, stock_location_id, ingredient_id) WHERE ingredient_id IS NOT NULL DO NOTHING;
                    UPDATE stock_balances SET quantity = quantity + NEW.quantity_delta, updated_at = NOW()
                    WHERE tenant_id = NEW.tenant_id AND ingredient_id = NEW.ingredient_id AND stock_location_id = NEW.stock_location_id
                    RETURNING unit_id INTO balance_unit;
                ELSE
                    INSERT INTO stock_balances (tenant_id, product_id, stock_location_id, unit_id, quantity)
                    VALUES (NEW.tenant_id, NEW.product_id, NEW.stock_location_id, NEW.unit_id, 0)
                    ON CONFLICT (tenant_id, stock_location_id, product_id) WHERE product_id IS NOT NULL DO NOTHING;
                    UPDATE stock_balances SET quantity = quantity + NEW.quantity_delta, updated_at = NOW()
                    WHERE tenant_id = NEW.tenant_id AND product_id = NEW.product_id AND stock_location_id = NEW.stock_location_id
                    RETURNING unit_id INTO balance_unit;
                END IF;

                IF balance_unit <> NEW.unit_id THEN
                    RAISE EXCEPTION 'movement unit differs from the item stock unit'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'ck_stock_movements_unit_consistency';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_stock_movements_apply
                AFTER INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION forno_stock_movements_apply()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_stock_movements_apply ON stock_movements');
        $this->addSql('DROP TRIGGER trg_stock_movements_immutable ON stock_movements');
        $this->addSql('DROP FUNCTION forno_stock_movements_apply()');
        $this->addSql('DROP FUNCTION forno_stock_movements_immutable()');
        $this->addSql('DROP TABLE stock_balances');
        $this->addSql('DROP TABLE stock_movements');
        $this->addSql('DROP TABLE stock_locations');
    }
}
