<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure\Security;

use App\Tenant\Application\TenantContext;
use App\Tenant\Domain\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autoriza atributos como "catalog.write" com base nas permissões do
 * usuário no tenant ativo. Uso: #[IsGranted('catalog.write')].
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== Permission::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        return $this->tenantContext->hasPermission($attribute);
    }
}
