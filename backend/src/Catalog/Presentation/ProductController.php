<?php

declare(strict_types=1);

namespace App\Catalog\Presentation;

use App\Catalog\Application\ProductService;
use App\Catalog\Domain\ProductionType;
use App\Catalog\Presentation\Request\ProductRequest;
use App\Shared\Presentation\ApiResponse;
use App\Shared\Presentation\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/products')]
final class ProductController
{
    public function __construct(
        private readonly ProductService $service,
        private readonly CatalogSerializer $serializer,
    ) {
    }

    #[Route('', name: 'api_products_list', methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function list(Request $request): JsonResponse
    {
        $pagination = Pagination::fromRequest($request);
        $result = $this->service->search([
            'q' => $request->query->getString('q'),
            'active' => QueryFilters::bool($request, 'active'),
            'categoryId' => QueryFilters::uuid($request, 'categoryId'),
            'productionType' => ProductionType::tryFrom($request->query->getString('productionType'))?->value,
        ], $pagination->offset(), $pagination->limit);

        return ApiResponse::page(
            array_map($this->serializer->product(...), $result['items']),
            $pagination->page,
            $pagination->limit,
            $result['total'],
        );
    }

    #[Route('/{id}', name: 'api_products_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function get(string $id): JsonResponse
    {
        return ApiResponse::data($this->serializer->product($this->service->get(Uuid::fromString($id))));
    }

    #[Route('', name: 'api_products_create', methods: ['POST'])]
    #[IsGranted('catalog.write')]
    public function create(#[MapRequestPayload] ProductRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->serializer->product($this->service->create($payload->toData())), 201);
    }

    #[Route('/{id}', name: 'api_products_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('catalog.write')]
    public function update(string $id, #[MapRequestPayload] ProductRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->serializer->product($this->service->update(Uuid::fromString($id), $payload->toData())));
    }
}
