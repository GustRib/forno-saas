<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\CategoryKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 20, enumType: CategoryKind::class)]
    private CategoryKind $kind;

    #[ORM\Column]
    private int $sortOrder;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $tenantId, string $name, CategoryKind $kind, int $sortOrder = 0)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->name = trim($name);
        $this->kind = $kind;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function update(string $name, CategoryKind $kind, int $sortOrder, bool $active): void
    {
        $this->name = trim($name);
        $this->kind = $kind;
        $this->sortOrder = $sortOrder;
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): CategoryKind
    {
        return $this->kind;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
