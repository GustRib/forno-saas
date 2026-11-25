<?php

declare(strict_types=1);

namespace App\Recipe\Domain\Model;

use App\Recipe\Domain\RecipeStatus;
use App\Shared\Domain\DomainException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ficha técnica versionada (FR-020, FR-023, DATABASE §3.10). Cada versão é
 * uma linha; só a versão DRAFT é editável. Ativar arquiva a anterior.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recipes')]
class Recipe
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $tenantId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $productId;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 6)]
    private string $expectedYieldQty = '0';

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $yieldUnitId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(length: 20, enumType: RecipeStatus::class)]
    private RecipeStatus $status = RecipeStatus::DRAFT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveFrom = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $createdBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, RecipeItem> */
    #[ORM\OneToMany(targetEntity: RecipeItem::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, RecipeAdditionalCost> */
    #[ORM\OneToMany(targetEntity: RecipeAdditionalCost::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $additionalCosts;

    public function __construct(Uuid $tenantId, Uuid $productId, int $version, RecipeContent $content, Uuid $createdBy)
    {
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->productId = $productId;
        $this->version = $version;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->items = new ArrayCollection();
        $this->additionalCosts = new ArrayCollection();
        $this->replaceContent($content);
    }

    /**
     * Substitui o conteúdo do rascunho. Itens do mesmo ingrediente são
     * atualizados no lugar (a tabela tem unique (recipe_id, ingredient_id)
     * e o Doctrine insere antes de remover órfãos).
     */
    public function replaceContent(RecipeContent $content): void
    {
        $this->assertDraft();

        $this->name = trim($content->name);
        $this->expectedYieldQty = $content->expectedYieldQty;
        $this->yieldUnitId = $content->yieldUnitId;
        $this->instructions = null === $content->instructions || '' === trim($content->instructions) ? null : $content->instructions;

        $existing = [];
        foreach ($this->items as $item) {
            $existing[$item->getIngredientId()->toRfc4122()] = $item;
        }
        $keep = [];
        foreach ($content->items as $position => $data) {
            $key = $data->ingredientId->toRfc4122();
            if (isset($existing[$key])) {
                $existing[$key]->apply($data, $position);
            } else {
                $this->items->add(new RecipeItem($this, $data, $position));
            }
            $keep[$key] = true;
        }
        foreach ($existing as $key => $item) {
            if (!isset($keep[$key])) {
                $this->items->removeElement($item);
            }
        }

        $this->additionalCosts->clear();
        foreach ($content->additionalCosts as $position => $cost) {
            $this->additionalCosts->add(new RecipeAdditionalCost($this, $cost['description'], $cost['amount'], $position));
        }

        $this->touch();
    }

    public function activate(\DateTimeImmutable $effectiveFrom): void
    {
        $this->assertDraft();
        $this->status = RecipeStatus::ACTIVE;
        $this->effectiveFrom = $effectiveFrom;
        $this->activatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    public function archive(): void
    {
        if (RecipeStatus::ACTIVE !== $this->status) {
            throw new \LogicException('Só a versão ativa pode ser arquivada.');
        }
        $this->status = RecipeStatus::ARCHIVED;
        $this->archivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();
    }

    public function content(): RecipeContent
    {
        return new RecipeContent(
            name: $this->name,
            expectedYieldQty: $this->expectedYieldQty,
            yieldUnitId: $this->yieldUnitId,
            instructions: $this->instructions,
            items: array_map(static fn (RecipeItem $i) => $i->toData(), $this->getItems()),
            additionalCosts: array_map(static fn (RecipeAdditionalCost $c) => ['description' => $c->getDescription(), 'amount' => $c->getAmount()], $this->getAdditionalCosts()),
        );
    }

    public function isDraft(): bool
    {
        return RecipeStatus::DRAFT === $this->status;
    }

    private function assertDraft(): void
    {
        if (RecipeStatus::DRAFT !== $this->status) {
            throw DomainException::conflict('Versões publicadas não podem ser alteradas. Crie uma nova versão.', 'RECIPE_NOT_DRAFT');
        }
    }

    private function touch(): void
    {
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

    public function getProductId(): Uuid
    {
        return $this->productId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getExpectedYieldQty(): string
    {
        return $this->expectedYieldQty;
    }

    public function getYieldUnitId(): Uuid
    {
        return $this->yieldUnitId;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function getStatus(): RecipeStatus
    {
        return $this->status;
    }

    public function getEffectiveFrom(): ?\DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getCreatedBy(): Uuid
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<RecipeItem>
     */
    public function getItems(): array
    {
        $items = $this->items->toArray();
        usort($items, static fn (RecipeItem $a, RecipeItem $b) => $a->getPosition() <=> $b->getPosition());

        return $items;
    }

    /**
     * @return list<RecipeAdditionalCost>
     */
    public function getAdditionalCosts(): array
    {
        $costs = $this->additionalCosts->toArray();
        usort($costs, static fn (RecipeAdditionalCost $a, RecipeAdditionalCost $b) => $a->getPosition() <=> $b->getPosition());

        return $costs;
    }
}
