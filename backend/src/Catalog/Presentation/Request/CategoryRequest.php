<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class CategoryRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o nome.')]
        #[Assert\Length(max: 100)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Selecione o tipo.')]
        #[Assert\Choice(choices: ['SELLABLE', 'INGREDIENT', 'BOTH'], message: 'Tipo inválido.')]
        public readonly string $kind = '',

        #[Assert\Range(min: 0, max: 100000)]
        public readonly int $sortOrder = 0,

        public readonly bool $active = true,
    ) {
    }
}
