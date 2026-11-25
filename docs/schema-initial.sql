-- Forno — schema inicial de referência (PostgreSQL)
-- ATENÇÃO: referência para revisão e conversão em migrations Doctrine.
-- O schema completo é descrito em DATABASE.md. Este arquivo implementa
-- a base de identidade, catálogo, unidades e estoque ledger para o primeiro incremento.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(160) NOT NULL,
    legal_name VARCHAR(180),
    tax_id VARCHAR(14),
    email VARCHAR(180),
    phone VARCHAR(30),
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'SUSPENDED', 'CLOSED')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX uq_tenants_tax_id ON tenants(tax_id) WHERE tax_id IS NOT NULL;

CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(160) NOT NULL,
    email VARCHAR(180) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INVITED', 'DISABLED')),
    email_verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX uq_users_email_lower ON users (LOWER(email));

CREATE TABLE tenant_users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    user_id UUID NOT NULL REFERENCES users(id),
    role VARCHAR(30) NOT NULL
        CHECK (role IN ('OWNER', 'MANAGER', 'CASHIER', 'PRODUCTION', 'STOCK')),
    permissions JSONB,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
        CHECK (status IN ('ACTIVE', 'INVITED', 'DISABLED')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, user_id),
    UNIQUE (tenant_id, id)
);
CREATE INDEX idx_tenant_users_user_status ON tenant_users(user_id, status);

CREATE TABLE categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    name VARCHAR(100) NOT NULL,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('SELLABLE', 'INGREDIENT', 'BOTH')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, kind, name)
);

CREATE TABLE units_of_measure (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    code VARCHAR(12) NOT NULL,
    name VARCHAR(40) NOT NULL,
    dimension VARCHAR(20) NOT NULL
        CHECK (dimension IN ('MASS', 'VOLUME', 'COUNT', 'LENGTH')),
    base_factor NUMERIC(18,9) NOT NULL CHECK (base_factor > 0),
    is_base BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, code)
);

CREATE TABLE products (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
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
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, category_id) REFERENCES categories(tenant_id, id),
    FOREIGN KEY (tenant_id, sale_unit_id) REFERENCES units_of_measure(tenant_id, id)
);
CREATE UNIQUE INDEX uq_products_tenant_sku ON products(tenant_id, sku) WHERE sku IS NOT NULL;
CREATE UNIQUE INDEX uq_products_tenant_barcode ON products(tenant_id, barcode) WHERE barcode IS NOT NULL;
CREATE INDEX idx_products_tenant_active_name ON products(tenant_id, active, name);

CREATE TABLE ingredients (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
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
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, category_id) REFERENCES categories(tenant_id, id),
    FOREIGN KEY (tenant_id, stock_unit_id) REFERENCES units_of_measure(tenant_id, id),
    FOREIGN KEY (tenant_id, purchase_unit_id) REFERENCES units_of_measure(tenant_id, id)
);
CREATE UNIQUE INDEX uq_ingredients_tenant_sku ON ingredients(tenant_id, sku) WHERE sku IS NOT NULL;
CREATE INDEX idx_ingredients_tenant_active_name ON ingredients(tenant_id, active, name);

CREATE TABLE stock_locations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    name VARCHAR(100) NOT NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (tenant_id, id),
    UNIQUE (tenant_id, name)
);

CREATE TABLE inventory_lots (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    ingredient_id UUID,
    product_id UUID,
    stock_location_id UUID NOT NULL,
    lot_code VARCHAR(80),
    received_at TIMESTAMPTZ,
    expires_at DATE,
    initial_qty NUMERIC(18,6) NOT NULL DEFAULT 0 CHECK (initial_qty >= 0),
    unit_cost NUMERIC(18,6) NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, ingredient_id) REFERENCES ingredients(tenant_id, id),
    FOREIGN KEY (tenant_id, product_id) REFERENCES products(tenant_id, id),
    FOREIGN KEY (tenant_id, stock_location_id) REFERENCES stock_locations(tenant_id, id),
    CHECK (num_nonnulls(ingredient_id, product_id) = 1)
);
CREATE INDEX idx_inventory_lots_expiry ON inventory_lots(tenant_id, expires_at);

CREATE TABLE stock_movements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    ingredient_id UUID,
    product_id UUID,
    inventory_lot_id UUID,
    stock_location_id UUID NOT NULL,
    movement_type VARCHAR(32) NOT NULL CHECK (movement_type IN (
        'OPENING_BALANCE', 'PURCHASE_RECEIPT', 'PRODUCTION_CONSUMPTION',
        'PRODUCTION_OUTPUT', 'SALE', 'WASTE', 'ADJUSTMENT_IN',
        'ADJUSTMENT_OUT', 'RETURN_IN', 'REVERSAL'
    )),
    quantity_delta NUMERIC(18,6) NOT NULL CHECK (quantity_delta <> 0),
    unit_id UUID NOT NULL,
    unit_cost NUMERIC(18,6) CHECK (unit_cost >= 0),
    source_type VARCHAR(40),
    source_id UUID,
    reversal_of_id UUID,
    reason VARCHAR(255),
    occurred_at TIMESTAMPTZ NOT NULL,
    created_by UUID NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, id),
    FOREIGN KEY (tenant_id, ingredient_id) REFERENCES ingredients(tenant_id, id),
    FOREIGN KEY (tenant_id, product_id) REFERENCES products(tenant_id, id),
    FOREIGN KEY (tenant_id, inventory_lot_id) REFERENCES inventory_lots(tenant_id, id),
    FOREIGN KEY (tenant_id, stock_location_id) REFERENCES stock_locations(tenant_id, id),
    FOREIGN KEY (tenant_id, unit_id) REFERENCES units_of_measure(tenant_id, id),
    FOREIGN KEY (tenant_id, reversal_of_id) REFERENCES stock_movements(tenant_id, id),
    CHECK (num_nonnulls(ingredient_id, product_id) = 1)
);
CREATE INDEX idx_stock_movements_ingredient_date
    ON stock_movements(tenant_id, ingredient_id, occurred_at);
CREATE INDEX idx_stock_movements_product_date
    ON stock_movements(tenant_id, product_id, occurred_at);
CREATE INDEX idx_stock_movements_source
    ON stock_movements(tenant_id, source_type, source_id);

-- Próximas migrations: recipes, production_orders, cash_sessions, sales,
-- sale_items, payments, cash_movements, expenses, customer_orders e audit_logs.
