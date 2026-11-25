<?php

declare(strict_types=1);

namespace App\Catalog\Presentation\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class UnitRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o código.')]
        #[Assert\Length(max: 12)]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9_]+$/', message: 'Use apenas letras, números e _.')]
        public readonly string $code = '',

        #[Assert\NotBlank(message: 'Informe o nome.')]
        #[Assert\Length(max: 40)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Selecione a dimensão.')]
        #[Assert\Choice(choices: ['MASS', 'VOLUME', 'COUNT', 'LENGTH'], message: 'Dimensão inválida.')]
        public readonly string $dimension = '',

        #[Assert\NotBlank(message: 'Informe o fator de conversão.')]
        #[Assert\Regex(pattern: '/^\d{1,9}(\.\d{1,9})?$/', message: 'Informe um número decimal positivo (ex.: 1000 ou 0.5).')]
        public readonly string $baseFactor = '',
    ) {
    }
}
