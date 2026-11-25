<?php

declare(strict_types=1);

namespace App\Catalog\Presentation;

use App\Catalog\Application\CategoryService;
use App\Catalog\Domain\CategoryKind;
use App\Catalog\Presentation\Request\CategoryRequest;
use App\Shared\Presentation\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/categories')]
final class CategoryController
{
    public function __construct(
        private readonly CategoryService $service,
        private readonly CatalogSerializer $serializer,
    ) {
    }

    #[Route('', name: 'api_categories_list', methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function list(Request $request): JsonResponse
    {
        $kind = CategoryKind::tryFrom((string) $request->query->get('kind', ''));
        $active = QueryFilters::bool($request, 'active');

        return ApiResponse::data(array_map($this->serializer->category(...), $this->service->list($kind, $active)));
    }

    #[Route('/{id}', name: 'api_categories_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function get(string $id): JsonResponse
    {
        return ApiResponse::data($this->serializer->category($this->service->get(Uuid::fromString($id))));
    }

    #[Route('', name: 'api_categories_create', methods: ['POST'])]
    #[IsGranted('catalog.write')]
    public function create(#[MapRequestPayload] CategoryRequest $payload): JsonResponse
    {
        $category = $this->service->create($payload->name, CategoryKind::from($payload->kind), $payload->sortOrder);

        return ApiResponse::data($this->serializer->category($category), 201);
    }

    #[Route('/{id}', name: 'api_categories_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('catalog.write')]
    public function update(string $id, #[MapRequestPayload] CategoryRequest $payload): JsonResponse
    {
        $category = $this->service->update(
            Uuid::fromString($id),
            $payload->name,
            CategoryKind::from($payload->kind),
            $payload->sortOrder,
            $payload->active,
        );

        return ApiResponse::data($this->serializer->category($category));
    }
}
