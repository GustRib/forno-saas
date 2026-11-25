<?php

declare(strict_types=1);

namespace App\Recipe\Presentation\Request;

use App\Recipe\Domain\Model\RecipeContent;
use App\Recipe\Domain\Model\RecipeItemData;
use App\Shared\Domain\Decimal;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corpo de POST /recipes (com productId) e PUT /recipes/{id}.
 * Itens e custos adicionais substituem o conteúdo inteiro do rascunho.
 */
final class RecipeRequest
{
    private const QTY = '/^\d{1,12}(\.\d{1,6})?$/';
    private const MONEY = '/^\d{1,12}(\.\d{1,2})?$/';

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $additionalCosts
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Selecione o produto.', groups: ['create'])]
        #[Assert\Uuid(message: 'Produto inválido.')]
        public readonly ?string $productId = null,

        #[Assert\NotBlank(message: 'Informe o nome da ficha técnica.')]
        #[Assert\Length(max: 160)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Informe o rendimento.')]
        #[Assert\Regex(pattern: self::QTY, message: 'Informe um rendimento válido (até 6 casas decimais).')]
        public readonly string $expectedYieldQty = '',

        #[Assert\NotBlank(message: 'Selecione a unidade do rendimento.')]
        #[Assert\Uuid(message: 'Unidade inválida.')]
        public readonly string $yieldUnitId = '',

        #[Assert\Length(max: 10000)]
        public readonly ?string $instructions = null,

        #[Assert\Count(max: 100, maxMessage: 'Use no máximo {{ limit }} ingredientes.')]
        #[Assert\All([
            new Assert\Collection(fields: [
                'ingredientId' => [new Assert\NotBlank(message: 'Selecione o ingrediente.'), new Assert\Type('string'), new Assert\Uuid(message: 'Ingrediente inválido.')],
                'quantity' => [new Assert\NotBlank(message: 'Informe a quantidade.'), new Assert\Sequentially([new Assert\Type('string', message: 'Informe a quantidade como texto decimal.'), new Assert\Regex(pattern: self::QTY, message: 'Informe uma quantidade válida (até 6 casas decimais).')])],
                'unitId' => [new Assert\NotBlank(message: 'Selecione a unidade.'), new Assert\Type('string'), new Assert\Uuid(message: 'Unidade inválida.')],
                'notes' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
            ]),
        ])]
        public readonly array $items = [],

        #[Assert\Count(max: 20, maxMessage: 'Use no máximo {{ limit }} custos adicionais.')]
        #[Assert\All([
            new Assert\Collection(fields: [
                'description' => [new Assert\NotBlank(message: 'Descreva o custo (ex.: embalagem).'), new Assert\Type('string'), new Assert\Length(max: 120)],
                'amount' => [new Assert\NotBlank(message: 'Informe o valor.'), new Assert\Sequentially([new Assert\Type('string', message: 'Informe o valor como texto decimal.'), new Assert\Regex(pattern: self::MONEY, message: 'Informe um valor válido (ex.: 3.50).')])],
            ]),
        ])]
        public readonly array $additionalCosts = [],
    ) {
    }

    public function toContent(): RecipeContent
    {
        return new RecipeContent(
            name: $this->name,
            expectedYieldQty: $this->expectedYieldQty,
            yieldUnitId: Uuid::fromString($this->yieldUnitId),
            instructions: $this->instructions,
            items: array_map(static fn (array $i) => new RecipeItemData(
                ingredientId: Uuid::fromString((string) $i['ingredientId']),
                quantity: (string) $i['quantity'],
                unitId: Uuid::fromString((string) $i['unitId']),
                notes: isset($i['notes']) && '' !== trim((string) $i['notes']) ? trim((string) $i['notes']) : null,
            ), $this->items),
            additionalCosts: array_map(static fn (array $c) => [
                'description' => trim((string) $c['description']),
                'amount' => Decimal::round((string) $c['amount'], 2),
            ], $this->additionalCosts),
        );
    }
}
