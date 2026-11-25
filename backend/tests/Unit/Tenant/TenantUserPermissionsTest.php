<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\Domain\Model\TenantUser;
use App\Tenant\Domain\Permission;
use App\Tenant\Domain\Role;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TenantUserPermissionsTest extends TestCase
{
    public function testOwnerHasEveryPermission(): void
    {
        $owner = new TenantUser(Uuid::v7(), Uuid::v7(), Role::OWNER);

        foreach (Permission::cases() as $permission) {
            self::assertTrue($owner->hasPermission($permission->value), $permission->value);
        }
    }

    public function testCashierCanReadButNotWriteCatalog(): void
    {
        $cashier = new TenantUser(Uuid::v7(), Uuid::v7(), Role::CASHIER);

        self::assertTrue($cashier->hasPermission('catalog.read'));
        self::assertFalse($cashier->hasPermission('catalog.write'));
        self::assertFalse($cashier->hasPermission('members.manage'));
    }

    public function testManagerCannotManageMembers(): void
    {
        $manager = new TenantUser(Uuid::v7(), Uuid::v7(), Role::MANAGER);

        self::assertTrue($manager->hasPermission('catalog.write'));
        self::assertTrue($manager->hasPermission('members.read'));
        self::assertFalse($manager->hasPermission('members.manage'));
    }

    public function testDisabledMembershipHasNoPermission(): void
    {
        $owner = new TenantUser(Uuid::v7(), Uuid::v7(), Role::OWNER);
        $owner->changeStatus(TenantUser::STATUS_DISABLED);

        self::assertFalse($owner->hasPermission('catalog.read'));
    }

    public function testUnknownPermissionsAreIgnored(): void
    {
        $cashier = new TenantUser(Uuid::v7(), Uuid::v7(), Role::CASHIER);

        self::assertNotContains('anything.else', $cashier->effectivePermissions());
    }
}
