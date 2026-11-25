<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * Papéis de um usuário dentro de um estabelecimento (tenant_users.role).
 */
enum Role: string
{
    case OWNER = 'OWNER';
    case MANAGER = 'MANAGER';
    case CASHIER = 'CASHIER';
    case PRODUCTION = 'PRODUCTION';
    case STOCK = 'STOCK';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Proprietário',
            self::MANAGER => 'Gerente',
            self::CASHIER => 'Operador de caixa',
            self::PRODUCTION => 'Produção',
            self::STOCK => 'Estoque',
        };
    }

    /**
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::OWNER => Permission::cases(),
            self::MANAGER => [
                Permission::MEMBERS_READ,
                Permission::CATALOG_READ,
                Permission::CATALOG_WRITE,
                Permission::INVENTORY_READ,
                Permission::INVENTORY_WRITE,
                Permission::INVENTORY_REVERSE,
                Permission::RECIPE_READ,
                Permission::RECIPE_WRITE,
            ],
            // REQUIREMENTS §2: produção/estoque registram perdas, recebimentos e
            // inventário; estornos ficam com quem "autoriza ajustes". Produção
            // "consulta receitas"; o caixa "não altera receitas, custos".
            self::PRODUCTION, self::STOCK => [
                Permission::CATALOG_READ,
                Permission::INVENTORY_READ,
                Permission::INVENTORY_WRITE,
                Permission::RECIPE_READ,
            ],
            self::CASHIER => [
                Permission::CATALOG_READ,
            ],
        };
    }
}
