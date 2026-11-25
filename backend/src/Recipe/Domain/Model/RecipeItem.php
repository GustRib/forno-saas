<?php

declare(strict_types=1);

namespace App\Recipe\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ingrediente da ficha técnica (FR-021), na quantidade e unidade digitadas
 * por lote. A conversão para a unidade de estoque acontece no cálculo de custo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recipe_items')]
class RecipeItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\ManyToOne(targetEntity: Recipe::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'recipe_id', nullable: false)]
    private Recipe $recipe;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $ingredientId;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $quantity;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $unitId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $notes;

    #[ORM\Column]
    private int $position;

    public function __construct(Recipe $recipe, RecipeItemData $data, int $position)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $recipe->getTenantId();
        $this->recipe = $recipe;
        $this->ingredientId = $data->ingredientId;
        $this->apply($data, $position);
    }

    public function apply(RecipeItemData $data, int $position): void
    {
        $this->quantity = $data->quantity;
        $this->unitId = $data->unitId;
        $this->notes = $data->notes;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIngredientId(): Uuid
    {
        return $this->ingredientId;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function getUnitId(): Uuid
    {
        return $this->unitId;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function toData(): RecipeItemData
    {
        return new RecipeItemData($this->ingredientId, $this->quantity, $this->unitId, $this->notes);
    }
}
