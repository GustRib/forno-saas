<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Request;

use App\Identity\Application\RegisterUser;
use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o nome da padaria.')]
        #[Assert\Length(max: 160)]
        public readonly string $bakeryName = '',

        #[Assert\NotBlank(message: 'Informe seu nome.')]
        #[Assert\Length(max: 160)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Informe o e-mail.')]
        #[Assert\Email(message: 'E-mail inválido.')]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',

        #[Assert\NotBlank(message: 'Informe a senha.')]
        #[Assert\Length(min: RegisterUser::MIN_PASSWORD_LENGTH, max: 4096, minMessage: 'A senha deve ter ao menos {{ limit }} caracteres.')]
        public readonly string $password = '',
    ) {
    }
}
