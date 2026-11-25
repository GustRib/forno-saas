<?php

declare(strict_types=1);

namespace App\Tenant\Presentation\Request;

use App\Tenant\Domain\Role;
use Symfony\Component\Validator\Constraints as Assert;

final class MemberRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o nome.', groups: ['create'])]
        #[Assert\Length(max: 160)]
        public readonly string $name = '',

        #[Assert\NotBlank(message: 'Informe o e-mail.', groups: ['create'])]
        #[Assert\Email(message: 'E-mail inválido.')]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',

        #[Assert\NotBlank(message: 'Selecione o papel.')]
        #[Assert\Choice(callback: [self::class, 'roles'], message: 'Papel inválido.')]
        public readonly string $role = '',

        #[Assert\Length(min: 8, max: 4096, minMessage: 'A senha deve ter ao menos {{ limit }} caracteres.', groups: ['create'])]
        public readonly ?string $password = null,

        #[Assert\Choice(choices: ['ACTIVE', 'DISABLED'], message: 'Situação inválida.')]
        public readonly string $status = 'ACTIVE',
    ) {
    }

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return array_map(static fn (Role $r) => $r->value, Role::cases());
    }
}
