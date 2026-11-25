<?php

declare(strict_types=1);

namespace App\Recipe\Presentation;

use App\Catalog\Application\RecipeCatalog;
use App\Recipe\Application\RecipeCostService;
use App\Recipe\Application\RecipeService;
use App\Recipe\Domain\Model\Recipe;
use App\Recipe\Domain\RecipeStatus;
use App\Recipe\Infrastructure\RecipeRepository;
use App\Recipe\Presentation\Request\RecipeRequest;
use App\Shared\Domain\DomainException;
use App\Shared\Presentation\ApiResponse;
use App\Shared\Presentation\Pagination;
use App\Tenant\Application\TenantContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/recipes')]
final class RecipeController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RecipeService $service,
        private readonly RecipeRepository $repository,
        private readonly RecipeCostService $costs,
        private readonly RecipeCatalog $catalog,
        private readonly RecipeSerializer $serializer,
    ) {
    }

    /**
     * GET /api/v1/recipes?productId=&status=DRAFT|ACTIVE|ARCHIVED&page=&limit=.
     */
    #[Route('', name: 'api_recipes_list', methods: ['GET'])]
    #[IsGranted('recipe.read')]
    public function list(Request $request): JsonResponse
    {
        $tenantId = $this->context->getTenantId();
        $pagination = Pagination::fromRequest($request, 100);
        $productId = $request->query->getString('productId');
        if ('' !== $productId && !Uuid::isValid($productId)) {
            throw DomainException::invalidField('productId', 'Produto inválido.');
        }

        $result = $this->repository->search(
            $tenantId,
            '' !== $productId ? Uuid::fromString($productId) : null,
            RecipeStatus::tryFrom($request->query->getString('status')),
            $pagination->offset(),
            $pagination->limit,
        );

        $productIds = array_values(array_unique(array_map(static fn (Recipe $r) => $r->getProductId()->toRfc4122(), $result['items'])));
        $products = $this->catalog->products($tenantId, array_map(static fn (string $id) => Uuid::fromString($id), $productIds));
        $units = $this->catalog->units($tenantId);

        return ApiResponse::page(
            array_map(fn (Recipe $r) => $this->serializer->summary($r, $products[$r->getProductId()->toRfc4122()] ?? null, $units), $result['items']),
            $pagination->page,
            $pagination->limit,
            $result['total'],
        );
    }

    #[Route('/{id}', name: 'api_recipes_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('recipe.read')]
    public function get(string $id): JsonResponse
    {
        return ApiResponse::data($this->detail($this->service->get(Uuid::fromString($id))));
    }

    #[Route('', name: 'api_recipes_create', methods: ['POST'])]
    #[IsGranted('recipe.write')]
    public function create(#[MapRequestPayload(validationGroups: ['Default', 'create'])] RecipeRequest $payload): JsonResponse
    {
        $recipe = $this->service->create(Uuid::fromString((string) $payload->productId), $payload->toContent());

        return ApiResponse::data($this->detail($recipe), 201);
    }

    #[Route('/{id}', name: 'api_recipes_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('recipe.write')]
    public function update(string $id, #[MapRequestPayload] RecipeRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->detail($this->service->update(Uuid::fromString($id), $payload->toContent())));
    }

    #[Route('/{id}', name: 'api_recipes_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('recipe.write')]
    public function delete(string $id): JsonResponse
    {
        $this->service->delete(Uuid::fromString($id));

        return ApiResponse::noContent();
    }

    #[Route('/{id}/new-version', name: 'api_recipes_new_version', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('recipe.write')]
    public function newVersion(string $id): JsonResponse
    {
        return ApiResponse::data($this->detail($this->service->newVersion(Uuid::fromString($id))), 201);
    }

    #[Route('/{id}/activate', name: 'api_recipes_activate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('recipe.write')]
    public function activate(string $id): JsonResponse
    {
        return ApiResponse::data($this->detail($this->service->activate(Uuid::fromString($id))));
    }

    #[Route('/{id}/cost-snapshots', name: 'api_recipes_snapshots', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('recipe.read')]
    public function snapshots(string $id): JsonResponse
    {
        $recipe = $this->service->get(Uuid::fromString($id));

        return ApiResponse::data(array_map($this->serializer->snapshot(...), $this->repository->snapshots($recipe->getTenantId(), $recipe->getId())));
    }

    #[Route('/{id}/cost-snapshots', name: 'api_recipes_snapshot_create', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('recipe.write')]
    public function recordSnapshot(string $id): JsonResponse
    {
        return ApiResponse::data($this->serializer->snapshot($this->service->recordCostSnapshot(Uuid::fromString($id))), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Recipe $recipe): array
    {
        $tenantId = $recipe->getTenantId();

        return $this->serializer->detail(
            $recipe,
            $this->catalog->product($tenantId, $recipe->getProductId()),
            $this->catalog->units($tenantId),
            $this->costs->calculate($recipe),
            $this->repository->versions($tenantId, $recipe->getProductId()),
        );
    }
}
