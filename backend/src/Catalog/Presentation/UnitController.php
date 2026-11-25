<?php

declare(strict_types=1);

namespace App\Catalog\Presentation;

use App\Catalog\Application\UnitService;
use App\Catalog\Domain\Dimension;
use App\Catalog\Presentation\Request\UnitRequest;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\DomainException;
use App\Shared\Presentation\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/units')]
final class UnitController
{
    public function __construct(
        private readonly UnitService $service,
        private readonly CatalogSerializer $serializer,
    ) {
    }

    #[Route('', name: 'api_units_list', methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function list(): JsonResponse
    {
        return ApiResponse::data(array_map($this->serializer->unit(...), $this->service->list()));
    }

    /**
     * GET /api/v1/units/convert?quantity=1.5&from={uuid}&to={uuid}.
     */
    #[Route('/convert', name: 'api_units_convert', methods: ['GET'])]
    #[IsGranted('catalog.read')]
    public function convert(Request $request): JsonResponse
    {
        $quantity = (string) $request->query->get('quantity', '');
        if (!Decimal::isValid($quantity)) {
            throw DomainException::invalidField('quantity', 'Informe uma quantidade decimal válida.');
        }
        $from = QueryFilters::uuid($request, 'from') ?? throw DomainException::invalidField('from', 'Unidade de origem inválida.');
        $to = QueryFilters::uuid($request, 'to') ?? throw DomainException::invalidField('to', 'Unidade de destino inválida.');

        $result = $this->service->convert($quantity, $from, $to);

        return ApiResponse::data([
            'quantity' => $quantity,
            'from' => $result['from']->getCode(),
            'to' => $result['to']->getCode(),
            'result' => $result['result'],
        ]);
    }

    #[Route('', name: 'api_units_create', methods: ['POST'])]
    #[IsGranted('catalog.write')]
    public function create(#[MapRequestPayload] UnitRequest $payload): JsonResponse
    {
        $unit = $this->service->create($payload->code, $payload->name, Dimension::from($payload->dimension), $payload->baseFactor);

        return ApiResponse::data($this->serializer->unit($unit), 201);
    }

    #[Route('/{id}', name: 'api_units_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[IsGranted('catalog.write')]
    public function update(string $id, #[MapRequestPayload] UnitRequest $payload): JsonResponse
    {
        $unit = $this->service->update(
            Uuid::fromString($id),
            $payload->code,
            $payload->name,
            Dimension::from($payload->dimension),
            $payload->baseFactor,
        );

        return ApiResponse::data($this->serializer->unit($unit));
    }

    #[Route('/{id}', name: 'api_units_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('catalog.write')]
    public function delete(string $id): JsonResponse
    {
        $this->service->delete(Uuid::fromString($id));

        return ApiResponse::noContent();
    }
}
