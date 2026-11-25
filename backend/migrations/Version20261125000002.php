<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Épico 2 — catálogo: categories, units_of_measure, products, ingredients.
 *
 * FKs compostas (tenant_id, x_id) garantem no banco que um item de um
 * tenant não referencia categoria/unidade de outro (DATABASE.md §4).
 */
final class Version20261125000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo: categorias, unidades, produtos e ingredientes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE categories (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                name VARCHAR(100) NOT NULL,
                kind VARCHAR(20) NOT NULL CHECK (kind IN ('SELLABLE', 'INGREDIENT', 'BOTH')),
                sort_order INTEGER NOT NULL DEFAULT 0,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_categories_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT uq_categories_tenant_kind_name UNIQUE (tenant_id, kind, name)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE units_of_measure (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                code VARCHAR(12) NOT NULL,
                name VARCHAR(40) NOT NULL,
                dimension VARCHAR(20) NOT NULL
                    CHECK (dimension IN ('MASS', 'VOLUME', 'COUNT', 'LENGTH')),
                base_factor NUMERIC(18,9) NOT NULL CHECK (base_factor > 0),
                is_base BOOLEAN NOT NULL DEFAULT FALSE,
                CONSTRAINT uq_units_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT uq_units_tenant_code UNIQUE (tenant_id, code)
            )
            SQL);
        // No máximo uma unidade-base por dimensão em cada tenant.
        $this->addSql('CREATE UNIQUE INDEX uq_units_tenant_base_dimension ON units_of_measure(tenant_id, dimension) WHERE is_base');

        $this->addSql(<<<'SQL'
            CREATE TABLE products (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                category_id UUID,
                name VARCHAR(160) NOT NULL,
                sku VARCHAR(64),
                barcode VARCHAR(64),
                description TEXT,
                sale_unit_id UUID NOT NULL,
                stock_tracked BOOLEAN NOT NULL DEFAULT TRUE,
                production_type VARCHAR(20) NOT NULL
                    CHECK (production_type IN ('MANUFACTURED', 'RESALE', 'SERVICE')),
                sale_price NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (sale_price >= 0),
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_products_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_products_category FOREIGN KEY (tenant_id, category_id) REFERENCES categories(tenant_id, id),
                CONSTRAINT fk_products_sale_unit FOREIGN KEY (tenant_id, sale_unit_id) REFERENCES units_of_measure(tenant_id, id),
                CONSTRAINT ck_products_service_not_tracked CHECK (production_type <> 'SERVICE' OR stock_tracked = FALSE)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_products_tenant_sku ON products(tenant_id, LOWER(sku)) WHERE sku IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_products_tenant_barcode ON products(tenant_id, barcode) WHERE barcode IS NOT NULL');
        $this->addSql('CREATE INDEX idx_products_tenant_active_name ON products(tenant_id, active, name)');
        $this->addSql('CREATE INDEX idx_products_tenant_category ON products(tenant_id, category_id)');
        $this->addSql('CREATE INDEX idx_products_tenant_sale_unit ON products(tenant_id, sale_unit_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE ingredients (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                category_id UUID,
                name VARCHAR(160) NOT NULL,
                sku VARCHAR(64),
                stock_unit_id UUID NOT NULL,
                purchase_unit_id UUID,
                purchase_to_stock_factor NUMERIC(18,6) CHECK (purchase_to_stock_factor > 0),
                average_unit_cost NUMERIC(18,6) NOT NULL DEFAULT 0 CHECK (average_unit_cost >= 0),
                minimum_stock NUMERIC(18,6) NOT NULL DEFAULT 0 CHECK (minimum_stock >= 0),
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_ingredients_tenant_id UNIQUE (tenant_id, id),
                CONSTRAINT fk_ingredients_category FOREIGN KEY (tenant_id, category_id) REFERENCES categories(tenant_id, id),
                CONSTRAINT fk_ingredients_stock_unit FOREIGN KEY (tenant_id, stock_unit_id) REFERENCES units_of_measure(tenant_id, id),
                CONSTRAINT fk_ingredients_purchase_unit FOREIGN KEY (tenant_id, purchase_unit_id) REFERENCES units_of_measure(tenant_id, id),
                CONSTRAINT ck_ingredients_purchase_factor CHECK (purchase_unit_id IS NULL OR purchase_to_stock_factor IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_ingredients_tenant_sku ON ingredients(tenant_id, LOWER(sku)) WHERE sku IS NOT NULL');
        $this->addSql('CREATE INDEX idx_ingredients_tenant_active_name ON ingredients(tenant_id, active, name)');
        $this->addSql('CREATE INDEX idx_ingredients_tenant_category ON ingredients(tenant_id, category_id)');
        $this->addSql('CREATE INDEX idx_ingredients_tenant_stock_unit ON ingredients(tenant_id, stock_unit_id)');
        $this->addSql('CREATE INDEX idx_ingredients_tenant_purchase_unit ON ingredients(tenant_id, purchase_unit_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingredients');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE units_of_measure');
        $this->addSql('DROP TABLE categories');
    }
}
