<?php

declare(strict_types=1);

namespace App\Tenant\Presentation;

use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\UserRepository;
use App\Shared\Presentation\ApiResponse;
use App\Tenant\Application\ManageMembers;
use App\Tenant\Application\TenantContext;
use App\Tenant\Domain\Model\TenantUser;
use App\Tenant\Domain\Role;
use App\Tenant\Infrastructure\TenantRepository;
use App\Tenant\Presentation\Request\MemberRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/members')]
final class MembersController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRepository $tenants,
        private readonly UserRepository $users,
        private readonly ManageMembers $manageMembers,
    ) {
    }

    #[Route('', name: 'api_members_list', methods: ['GET'])]
    #[IsGranted('members.read')]
    public function list(): JsonResponse
    {
        $members = $this->tenants->listMembers($this->context->getTenantId());
        $users = $this->users->findByIds(array_map(static fn (TenantUser $m) => $m->getUserId(), $members));

        return ApiResponse::data(array_map(
            fn (TenantUser $m) => $this->serialize($m, $users[$m->getUserId()->toRfc4122()] ?? null),
            $members,
        ));
    }

    #[Route('', name: 'api_members_create', methods: ['POST'])]
    #[IsGranted('members.manage')]
    public function create(#[MapRequestPayload(validationGroups: ['Default', 'create'])] MemberRequest $payload): JsonResponse
    {
        $membership = $this->manageMembers->add($payload->name, $payload->email, Role::from($payload->role), $payload->password);

        return ApiResponse::data($this->serialize($membership, $this->users->find($membership->getUserId())), 201);
    }

    #[Route('/{id}', name: 'api_members_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('members.manage')]
    public function update(string $id, #[MapRequestPayload] MemberRequest $payload): JsonResponse
    {
        $membership = $this->manageMembers->update(Uuid::fromString($id), Role::from($payload->role), $payload->status);

        return ApiResponse::data($this->serialize($membership, $this->users->find($membership->getUserId())));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TenantUser $membership, ?User $user): array
    {
        return [
            'id' => $membership->getId()->toRfc4122(),
            'userId' => $membership->getUserId()->toRfc4122(),
            'name' => $user?->getName(),
            'email' => $user?->getEmail(),
            'role' => $membership->getRole()->value,
            'roleLabel' => $membership->getRole()->label(),
            'status' => $membership->getStatus(),
            'permissions' => $membership->effectivePermissions(),
            'createdAt' => $membership->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
