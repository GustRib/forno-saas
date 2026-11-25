<?php

declare(strict_types=1);

namespace App\Tenant\Domain\Model;

use App\Tenant\Domain\Permission;
use App\Tenant\Domain\Role;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Associação explícita usuário ↔ estabelecimento (FR-002), com papel e
 * exceções de permissão opcionais no formato {"grant": [...], "revoke": [...]}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant_users')]
class TenantUser
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INVITED = 'INVITED';
    public const STATUS_DISABLED = 'DISABLED';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Column(length: 30, enumType: Role::class)]
    private Role $role;

    /** @var array{grant?: list<string>, revoke?: list<string>}|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $permissions = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Uuid $tenantId, Uuid $userId, Role $role)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function changeRole(Role $role): void
    {
        $this->role = $role;
    }

    public function changeStatus(string $status): void
    {
        if (!\in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Situação inválida.');
        }
        $this->status = $status;
    }

    /**
     * Permissões efetivas = padrão do papel + concessões − revogações.
     * O proprietário sempre tem todas as permissões.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        $permissions = array_map(static fn (Permission $p) => $p->value, $this->role->defaultPermissions());
        if (Role::OWNER === $this->role) {
            return $permissions;
        }

        $known = Permission::values();
        $grant = array_intersect($this->permissions['grant'] ?? [], $known);
        $revoke = $this->permissions['revoke'] ?? [];

        return array_values(array_diff(array_unique([...$permissions, ...$grant]), $revoke));
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isActive() && \in_array($permission, $this->effectivePermissions(), true);
    }
}
