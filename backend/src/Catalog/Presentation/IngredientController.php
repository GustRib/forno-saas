<?php

declare(strict_types=1);

namespace App\Catalog\Presentation;

use App\Catalog\Application\IngredientCostLedger;
use App\Catalog\Application\IngredientService;
use App\Catalog\Domain\Model\IngredientCostChange;
use App\Catalog\Presentation\Request\IngredientRequest;
use App\Identity\Infrastructure\UserRepository;
use App\Shared\Presentation\ApiResponse;
use App\Shared\Presentation\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/ingredients')]
final class IngredientController
{
    public function __construct(
        private readonly IngredientService $service,
        private readonly CatalogSerializer $serializer,
    ) {
    }

    #[Route('', name: 'api_ingredients_list', methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function list(Request $request): JsonResponse
    {
        $pagination = Pagination::fromRequest($request);
        $result = $this->service->search([
            'q' => $request->query->getString('q'),
            'active' => QueryFilters::bool($request, 'active'),
            'categoryId' => QueryFilters::uuid($request, 'categoryId'),
        ], $pagination->offset(), $pagination->limit);

        return ApiResponse::page(
            array_map($this->serializer->ingredient(...), $result['items']),
            $pagination->page,
            $pagination->limit,
            $result['total'],
        );
    }

    #[Route('/{id}', name: 'api_ingredients_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function get(string $id): JsonResponse
    {
        return ApiResponse::data($this->serializer->ingredient($this->service->get(Uuid::fromString($id))));
    }

    /**
     * FNO-044 / FR-025: histórico de custo (data, origem, valor anterior e novo).
     */
    #[Route('/{id}/cost-history', name: 'api_ingredients_cost_history', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function costHistory(string $id, Request $request, IngredientCostLedger $costs, UserRepository $users): JsonResponse
    {
        $ingredient = $this->service->get(Uuid::fromString($id));
        $pagination = Pagination::fromRequest($request);
        $result = $costs->history($ingredient->getTenantId(), $ingredient->getId(), $pagination->offset(), $pagination->limit);

        $userIds = array_values(array_filter(array_map(static fn (IngredientCostChange $c) => $c->getChangedBy(), $result['items'])));
        $names = $users->findByIds($userIds);

        return ApiResponse::page(
            array_map(fn (IngredientCostChange $c) => $this->serializer->costChange($c, $names[(string) $c->getChangedBy()?->toRfc4122()] ?? null), $result['items']),
            $pagination->page,
            $pagination->limit,
            $result['total'],
        );
    }

    #[Route('', name: 'api_ingredients_create', methods: ['POST'])]
    #[IsGranted('catalog.write')]
    public function create(#[MapRequestPayload] IngredientRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->serializer->ingredient($this->service->create($payload->toData())), 201);
    }

    #[Route('/{id}', name: 'api_ingredients_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('catalog.write')]
    public function update(string $id, #[MapRequestPayload] IngredientRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->serializer->ingredient($this->service->update(Uuid::fromString($id), $payload->toData())));
    }
}
