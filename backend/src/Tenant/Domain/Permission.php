<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * Permissões por módulo/ação (REQUIREMENTS §2). Validadas no backend pelo
 * PermissionVoter; o frontend só as usa para esconder botões.
 *
 * Novos módulos acrescentam permissões aqui quando forem implementados.
 */
enum Permission: string
{
    case TENANT_MANAGE = 'tenant.manage';
    case MEMBERS_READ = 'members.read';
    case MEMBERS_MANAGE = 'members.manage';
    case CATALOG_READ = 'catalog.read';
    case CATALOG_WRITE = 'catalog.write';
    /** Consultar saldos e histórico de movimentações. */
    case INVENTORY_READ = 'inventory.read';
    /** Saldo inicial, perdas, devoluções e ajustes. */
    case INVENTORY_WRITE = 'inventory.write';
    /** Estornar movimentações já lançadas. */
    case INVENTORY_REVERSE = 'inventory.reverse';
    /** Consultar fichas técnicas e seus custos estimados. */
    case RECIPE_READ = 'recipe.read';
    /** Criar, editar, versionar e ativar fichas técnicas. */
    case RECIPE_WRITE = 'recipe.write';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }
}
