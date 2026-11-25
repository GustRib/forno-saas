<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\UserRepository;
use App\Shared\Domain\DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Caso de uso público do módulo Identity: cria credencial de usuário.
 * Não faz flush: quem orquestra controla a transação.
 */
final class RegisterUser
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(string $name, string $email, string $plainPassword): User
    {
        if (null !== $this->users->findByEmail($email)) {
            throw DomainException::conflict('Já existe uma conta com este e-mail.', 'EMAIL_ALREADY_USED');
        }

        if (mb_strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            throw DomainException::invalidField('password', \sprintf('A senha deve ter ao menos %d caracteres.', self::MIN_PASSWORD_LENGTH));
        }

        $user = new User($name, $email);
        $user->changePassword($this->hasher->hashPassword($user, $plainPassword));
        $this->users->add($user);

        return $user;
    }
}
