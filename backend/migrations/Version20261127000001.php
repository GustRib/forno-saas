<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 1. Correção do épico 3 (auditoria): stock_balances só muda pelo ledger.
 * 2. Política de custo WAC-1: ingredient_cost_history (data e origem do custo, FR-025).
 * 3. Épico 4: recipes (versionadas, DATABASE §3.10), recipe_items (§3.11),
 *    recipe_additional_costs (FR-024) e recipe_cost_snapshots (FNO-044).
 *
 * Versionamento garantido pelo banco (FR-023): só rascunhos (DRAFT) mudam;
 * a versão ativa só pode ser arquivada; arquivadas e snapshots são imutáveis.
 */
final class Version20261127000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda de stock_balances, histórico de custo (WAC-1) e receitas versionadas';
    }

    public function up(Schema $schema): void
    {
        // --- 1. stock_balances: escrita só pelo trigger do ledger -------------
        // pg_trigger_depth() = 1 quando a instrução vem direto da aplicação; > 1
        // quando vem de dentro de forno_stock_movements_apply. A única escrita
        // direta permitida é criar a linha com saldo 0 (StockRepository::lockBalance).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION forno_stock_balances_guard() RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() > 1 THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF TG_OP = 'INSERT' AND NEW.quantity = 0 THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'stock_balances is maintained by the stock ledger; post a stock movement instead'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_stock_balances_guard
                BEFORE INSERT OR UPDATE OR DELETE ON stock_balances
                FOR EACH ROW EXECUTE FUNCTION forno_stock_balances_guard()
            SQL);

        // Função genérica para tabelas append-only.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION forno_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // --- 2. Histórico de custo do ingrediente ----------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE ingredient_cost_history (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                ingredient_id UUID NOT NULL,
                previous_cost NUMERIC(18,6) CHECK (previous_cost >= 0),
                new_cost NUMERIC(18,6) NOT NULL CHECK (new_cost >= 0),
                source_type VARCHAR(20) NOT NULL CHECK (source_type IN ('INITIAL', 'MANUAL', 'STOCK_MOVEMENT')),
                stock_movement_id UUID,
                cost_policy VARCHAR(20),
                changed_by UUID REFERENCES users(id),
                changed_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT uq_ingredient_cost_history_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_ingredient_cost_history_ingredient FOREIGN KEY (tenant_id, ingredient_id) REFERENCES ingredients(tenant_id, id),
                CONSTRAINT fk_ingredient_cost_history_movement FOREIGN KEY (tenant_id, stock_movement_id) REFERENCES stock_movements(tenant_id, id),
                CONSTRAINT ck_ingredient_cost_history_source CHECK (
                    (source_type = 'STOCK_MOVEMENT') = (stock_movement_id IS NOT NULL)
                    AND (source_type <> 'STOCK_MOVEMENT' OR cost_policy IS NOT NULL)
                )
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ingredient_cost_history_item ON ingredient_cost_history(tenant_id, ingredient_id, changed_at)');
        $this->addSql('CREATE INDEX idx_ingredient_cost_history_movement ON ingredient_cost_history(tenant_id, stock_movement_id)');
        $this->addSql('CREATE INDEX idx_ingredient_cost_history_user ON ingredient_cost_history(changed_by)');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_ingredient_cost_history_append_only
                BEFORE UPDATE OR DELETE ON ingredient_cost_history
                FOR EACH ROW EXECUTE FUNCTION forno_append_only()
            SQL);
        // Custo vigente de quem já existia vira o primeiro registro do histórico.
        $this->addSql(<<<'SQL'
            INSERT INTO ingredient_cost_history (id, tenant_id, ingredient_id, previous_cost, new_cost, source_type, changed_at)
            SELECT gen_random_uuid(), i.tenant_id, i.id, NULL, i.average_unit_cost, 'INITIAL', i.updated_at
            FROM ingredients i
            SQL);

        // --- 3. Receitas ------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE recipes (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                product_id UUID NOT NULL,
                version INTEGER NOT NULL CHECK (version >= 1),
                name VARCHAR(160) NOT NULL,
                expected_yield_qty NUMERIC(18,6) NOT NULL CHECK (expected_yield_qty > 0),
                yield_unit_id UUID NOT NULL,
                instructions TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'ACTIVE', 'ARCHIVED')),
                effective_from DATE,
                activated_at TIMESTAMPTZ,
                archived_at TIMESTAMPTZ,
                created_by UUID NOT NULL REFERENCES users(id),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_recipes_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT uq_recipes_product_version UNIQUE (tenant_id, product_id, version),
                CONSTRAINT fk_recipes_product FOREIGN KEY (tenant_id, product_id) REFERENCES products(tenant_id, id),
                CONSTRAINT fk_recipes_yield_unit FOREIGN KEY (tenant_id, yield_unit_id) REFERENCES units_of_measure(tenant_id, id),
                CONSTRAINT ck_recipes_status_dates CHECK (
                    (status = 'DRAFT' AND activated_at IS NULL AND archived_at IS NULL AND effective_from IS NULL)
                    OR (status = 'ACTIVE' AND activated_at IS NOT NULL AND archived_at IS NULL AND effective_from IS NOT NULL)
                    OR (status = 'ARCHIVED' AND activated_at IS NOT NULL AND archived_at IS NOT NULL AND effective_from IS NOT NULL)
                )
            )
            SQL);
        // DATABASE §3.10: uma receita ativa por produto. E no máximo um rascunho.
        $this->addSql("CREATE UNIQUE INDEX uq_recipes_one_active ON recipes(tenant_id, product_id) WHERE status = 'ACTIVE'");
        $this->addSql("CREATE UNIQUE INDEX uq_recipes_one_draft ON recipes(tenant_id, product_id) WHERE status = 'DRAFT'");
        $this->addSql('CREATE INDEX idx_recipes_tenant_status ON recipes(tenant_id, status)');
        $this->addSql('CREATE INDEX idx_recipes_yield_unit ON recipes(tenant_id, yield_unit_id)');
        $this->addSql('CREATE INDEX idx_recipes_created_by ON recipes(created_by)');

        $this->addSql(<<<'SQL'
            CREATE TABLE recipe_items (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                recipe_id UUID NOT NULL,
                ingredient_id UUID NOT NULL,
                quantity NUMERIC(18,6) NOT NULL CHECK (quantity > 0),
                unit_id UUID NOT NULL,
                notes VARCHAR(255),
                position INTEGER NOT NULL DEFAULT 0,
                CONSTRAINT uq_recipe_items_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT uq_recipe_items_ingredient UNIQUE (recipe_id, ingredient_id),
                CONSTRAINT fk_recipe_items_recipe FOREIGN KEY (tenant_id, recipe_id) REFERENCES recipes(tenant_id, id) ON DELETE CASCADE,
                CONSTRAINT fk_recipe_items_ingredient FOREIGN KEY (tenant_id, ingredient_id) REFERENCES ingredients(tenant_id, id),
                CONSTRAINT fk_recipe_items_unit FOREIGN KEY (tenant_id, unit_id) REFERENCES units_of_measure(tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_recipe_items_recipe ON recipe_items(tenant_id, recipe_id)');
        $this->addSql('CREATE INDEX idx_recipe_items_ingredient ON recipe_items(tenant_id, ingredient_id)');
        $this->addSql('CREATE INDEX idx_recipe_items_unit ON recipe_items(tenant_id, unit_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE recipe_additional_costs (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                recipe_id UUID NOT NULL,
                description VARCHAR(120) NOT NULL CHECK (btrim(description) <> ''),
                amount NUMERIC(14,2) NOT NULL CHECK (amount > 0),
                position INTEGER NOT NULL DEFAULT 0,
                CONSTRAINT uq_recipe_additional_costs_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_recipe_additional_costs_recipe FOREIGN KEY (tenant_id, recipe_id) REFERENCES recipes(tenant_id, id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_recipe_additional_costs_recipe ON recipe_additional_costs(tenant_id, recipe_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE recipe_cost_snapshots (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                recipe_id UUID NOT NULL,
                reason VARCHAR(20) NOT NULL CHECK (reason IN ('ACTIVATION', 'MANUAL')),
                cost_policy VARCHAR(20) NOT NULL,
                ingredients_cost NUMERIC(14,2) NOT NULL CHECK (ingredients_cost >= 0),
                additional_cost NUMERIC(14,2) NOT NULL CHECK (additional_cost >= 0),
                total_cost NUMERIC(14,2) NOT NULL CHECK (total_cost >= 0),
                cost_per_yield_unit NUMERIC(18,6) NOT NULL CHECK (cost_per_yield_unit >= 0),
                cost_per_sale_unit NUMERIC(18,6) CHECK (cost_per_sale_unit >= 0),
                yield_qty NUMERIC(18,6) NOT NULL CHECK (yield_qty > 0),
                yield_unit_id UUID NOT NULL,
                details JSONB NOT NULL,
                created_by UUID NOT NULL REFERENCES users(id),
                created_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT uq_recipe_cost_snapshots_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_recipe_cost_snapshots_recipe FOREIGN KEY (tenant_id, recipe_id) REFERENCES recipes(tenant_id, id),
                CONSTRAINT fk_recipe_cost_snapshots_unit FOREIGN KEY (tenant_id, yield_unit_id) REFERENCES units_of_measure(tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_recipe_cost_snapshots_recipe ON recipe_cost_snapshots(tenant_id, recipe_id, created_at)');
        $this->addSql('CREATE INDEX idx_recipe_cost_snapshots_unit ON recipe_cost_snapshots(tenant_id, yield_unit_id)');
        $this->addSql('CREATE INDEX idx_recipe_cost_snapshots_user ON recipe_cost_snapshots(created_by)');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_recipe_cost_snapshots_append_only
                BEFORE UPDATE OR DELETE ON recipe_cost_snapshots
                FOR EACH ROW EXECUTE FUNCTION forno_append_only()
            SQL);

        // Versão publicada é imutável: ACTIVE só pode virar ARCHIVED; ARCHIVED nunca muda.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION forno_recipes_versioning() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only DRAFT recipes can be deleted' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id OR NEW.product_id <> OLD.product_id OR NEW.version <> OLD.version THEN
                    RAISE EXCEPTION 'recipe identity cannot change' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'DRAFT' THEN
                    IF NEW.status = 'ARCHIVED' THEN
                        RAISE EXCEPTION 'a DRAFT recipe must be activated before being archived' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status = 'ACTIVE' AND NEW.status = 'ARCHIVED'
                    AND (NEW.name, NEW.expected_yield_qty, NEW.yield_unit_id, NEW.instructions, NEW.effective_from, NEW.activated_at, NEW.created_by, NEW.created_at)
                        IS NOT DISTINCT FROM
                        (OLD.name, OLD.expected_yield_qty, OLD.yield_unit_id, OLD.instructions, OLD.effective_from, OLD.activated_at, OLD.created_by, OLD.created_at) THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'published recipe versions are immutable; create a new version'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_recipes_versioning
                BEFORE UPDATE OR DELETE ON recipes
                FOR EACH ROW EXECUTE FUNCTION forno_recipes_versioning()
            SQL);

        // Itens e custos adicionais só mudam enquanto a receita é rascunho.
        // (Receita inexistente = exclusão em cascata de um rascunho: permitido.)
        $this->addSql(<<<'SQL'
            CREATE FUNCTION forno_recipe_children_draft_only() RETURNS trigger AS $$
            DECLARE
                parent_status VARCHAR(20);
            BEGIN
                SELECT status INTO parent_status FROM recipes
                    WHERE id = COALESCE(NEW.recipe_id, OLD.recipe_id);
                IF TG_OP = 'UPDATE' AND NEW.recipe_id <> OLD.recipe_id THEN
                    RAISE EXCEPTION 'recipe line cannot move to another recipe' USING ERRCODE = 'restrict_violation';
                END IF;
                IF parent_status IS NOT NULL AND parent_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'published recipe versions are immutable; create a new version'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_recipe_items_draft_only
                BEFORE INSERT OR UPDATE OR DELETE ON recipe_items
                FOR EACH ROW EXECUTE FUNCTION forno_recipe_children_draft_only()
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_recipe_additional_costs_draft_only
                BEFORE INSERT OR UPDATE OR DELETE ON recipe_additional_costs
                FOR EACH ROW EXECUTE FUNCTION forno_recipe_children_draft_only()
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recipe_cost_snapshots');
        $this->addSql('DROP TABLE recipe_additional_costs');
        $this->addSql('DROP TABLE recipe_items');
        $this->addSql('DROP TABLE recipes');
        $this->addSql('DROP FUNCTION forno_recipe_children_draft_only()');
        $this->addSql('DROP FUNCTION forno_recipes_versioning()');
        $this->addSql('DROP TABLE ingredient_cost_history');
        $this->addSql('DROP FUNCTION forno_append_only()');
        $this->addSql('DROP TRIGGER trg_stock_balances_guard ON stock_balances');
        $this->addSql('DROP FUNCTION forno_stock_balances_guard()');
    }
}
