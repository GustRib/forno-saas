<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Usuário global (pode pertencer a vários estabelecimentos via tenant_users).
 * Papéis e permissões NÃO ficam aqui: dependem do tenant ativo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INVITED = 'INVITED';
    public const STATUS_DISABLED = 'DISABLED';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $email)
    {
        $this->id = Uuid::v7();
        $this->name = trim($name);
        $this->email = self::normalizeEmail($email);
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function changePassword(string $hash): void
    {
        $this->passwordHash = $hash;
        $this->touch();
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function disable(): void
    {
        $this->status = self::STATUS_DISABLED;
        $this->touch();
    }

    public function activate(): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->touch();
    }

    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : throw new \LogicException('Usuário sem e-mail.');
    }

    /**
     * O Symfony exige ao menos um papel; a autorização real é feita
     * por permissões do tenant ativo (PermissionVoter).
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Não serializa o hash da senha na sessão; guarda apenas um digest
     * (técnica recomendada pelo Symfony 7.3+).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0passwordHash"] = hash('crc32c', $this->passwordHash);

        return $data;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
