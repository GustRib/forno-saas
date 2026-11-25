<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\Dimension;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Unidade de medida configurada para o tenant. `baseFactor` converte para a
 * unidade-base da dimensão (G para massa, ML para volume, UN para contagem).
 */
#[ORM\Entity]
#[ORM\Table(name: 'units_of_measure')]
class UnitOfMeasure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(length: 12)]
    private string $code;

    #[ORM\Column(length: 40)]
    private string $name;

    #[ORM\Column(length: 20, enumType: Dimension::class)]
    private Dimension $dimension;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 9)]
    private string $baseFactor;

    #[ORM\Column]
    private bool $isBase;

    public function __construct(Uuid $tenantId, string $code, string $name, Dimension $dimension, string $baseFactor, bool $isBase = false)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->code = self::normalizeCode($code);
        $this->name = trim($name);
        $this->dimension = $dimension;
        $this->baseFactor = $baseFactor;
        $this->isBase = $isBase;
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    /**
     * Só deve ser chamado quando a unidade ainda não é usada por nenhum item.
     */
    public function redefine(string $code, Dimension $dimension, string $baseFactor): void
    {
        $this->code = self::normalizeCode($code);
        $this->dimension = $dimension;
        $this->baseFactor = $baseFactor;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDimension(): Dimension
    {
        return $this->dimension;
    }

    public function getBaseFactor(): string
    {
        return $this->baseFactor;
    }

    public function isBase(): bool
    {
        return $this->isBase;
    }
}
