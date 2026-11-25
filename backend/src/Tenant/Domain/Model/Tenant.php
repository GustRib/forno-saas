<?php

declare(strict_types=1);

namespace App\Tenant\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Estabelecimento (padaria) que assina o Forno.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenants')]
class Tenant
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_CLOSED = 'CLOSED';

    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 64)]
    private string $timezone = self::DEFAULT_TIMEZONE;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency = 'BRL';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
        $this->name = trim($name);
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function updateProfile(string $name, ?string $legalName, ?string $taxId, ?string $email, ?string $phone, string $timezone): void
    {
        $this->name = trim($name);
        $this->legalName = $legalName;
        $this->taxId = $taxId;
        $this->email = $email;
        $this->phone = $phone;
        $this->timezone = $timezone;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
