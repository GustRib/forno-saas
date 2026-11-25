<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Local de estoque. No MVP cada tenant tem um local padrão ("Estoque geral");
 * o modelo já permite vários (DATABASE §3.12).
 */
#[ORM\Entity]
#[ORM\Table(name: 'stock_locations')]
class StockLocation
{
    public const DEFAULT_NAME = 'Estoque geral';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private bool $isDefault;

    public function __construct(Uuid $tenantId, string $name, bool $isDefault = false)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->name = trim($name);
        $this->isDefault = $isDefault;
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

    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}
