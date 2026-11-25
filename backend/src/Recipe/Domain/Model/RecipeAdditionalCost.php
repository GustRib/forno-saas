<?php

declare(strict_types=1);

namespace App\Recipe\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * FR-024: custo adicional estimado por lote (embalagem, energia, mão de obra),
 * mantido separado do custo de ingredientes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recipe_additional_costs')]
class RecipeAdditionalCost
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Recipe::class, inversedBy: 'additionalCosts')]
    #[ORM\JoinColumn(name: 'recipe_id', nullable: false)]
    private Recipe $recipe;

    #[ORM\Column(length: 120)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount;

    #[ORM\Column]
    private int $position;

    public function __construct(Recipe $recipe, string $description, string $amount, int $position)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $recipe->getTenantId();
        $this->recipe = $recipe;
        $this->description = trim($description);
        $this->amount = $amount;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
