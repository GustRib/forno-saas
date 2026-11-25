<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Épico 1 — identidade e multi-tenant: tenants, users, tenant_users.
 *
 * Migrations são escritas à mão (fonte da verdade do schema). Não gerar com
 * `doctrine:migrations:diff` sem revisão: o mapeamento ORM não representa as
 * FKs compostas (tenant_id, id) nem índices funcionais/parciais.
 */
final class Version20261125000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identidade e multi-tenant: tenants, users e tenant_users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id UUID PRIMARY KEY,
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
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_tenants_tax_id ON tenants(tax_id) WHERE tax_id IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id UUID PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
                    CHECK (status IN ('ACTIVE', 'INVITED', 'DISABLED')),
                email_verified_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_users_email_lower ON users (LOWER(email))');

        $this->addSql(<<<'SQL'
            CREATE TABLE tenant_users (
                id UUID PRIMARY KEY,
                tenant_id UUID NOT NULL REFERENCES tenants(id),
                user_id UUID NOT NULL REFERENCES users(id),
                role VARCHAR(30) NOT NULL
                    CHECK (role IN ('OWNER', 'MANAGER', 'CASHIER', 'PRODUCTION', 'STOCK')),
                permissions JSONB,
                status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'
                    CHECK (status IN ('ACTIVE', 'INVITED', 'DISABLED')),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_tenant_users_tenant_user UNIQUE (tenant_id, user_id),
                CONSTRAINT uq_tenant_users_tenant_id UNIQUE (tenant_id, id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_tenant_users_user_status ON tenant_users(user_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_users');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE tenants');
    }
}
