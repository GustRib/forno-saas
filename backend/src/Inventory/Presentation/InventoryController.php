<?php

declare(strict_types=1);

namespace App\Inventory\Presentation;

use App\Catalog\Domain\StockItemType;
use App\Inventory\Application\InventoryQueries;
use App\Inventory\Application\StockLedger;
use App\Inventory\Domain\MovementType;
use App\Inventory\Presentation\Request\MovementRequest;
use App\Inventory\Presentation\Request\ReversalRequest;
use App\Shared\Domain\DomainException;
use App\Shared\Presentation\ApiResponse;
use App\Shared\Presentation\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class InventoryController
{
    public function __construct(
        private readonly InventoryQueries $queries,
        private readonly StockLedger $ledger,
        private readonly InventorySerializer $serializer,
    ) {
    }

    #[Route('/api/v1/stock-locations', name: 'api_stock_locations', methods: ['GET'])]
    #[IsGranted('inventory.read')]
    public function locations(): JsonResponse
    {
        return ApiResponse::data(array_map($this->serializer->location(...), $this->queries->locations()));
    }

    /**
     * GET /api/v1/stock/balances?itemType=INGREDIENT&q=&locationId=&includeInactive=1&page=&limit=.
     */
    #[Route('/api/v1/stock/balances', name: 'api_stock_balances', methods: ['GET'])]
    #[IsGranted('inventory.read')]
    public function balances(Request $request): JsonResponse
    {
        $pagination = Pagination::fromRequest($request);
        $type = StockItemType::tryFrom($request->query->getString('itemType', 'INGREDIENT'))
            ?? throw DomainException::invalidField('itemType', 'Tipo de item inválido.');

        $result = $this->queries->overview(
            $type,
            $request->query->getString('q'),
            $this->uuidQuery($request, 'locationId'),
            !$request->query->getBoolean('includeInactive'),
            $pagination->offset(),
            $pagination->limit,
        );

        $location = $this->serializer->location($result['location']);
        $items = array_map(fn (array $row) => [
            ...$this->serializer->item($row['item']),
            'quantity' => InventorySerializer::decimal($row['quantity']),
            'location' => $location,
        ], $result['rows']);

        return ApiResponse::page($items, $pagination->page, $pagination->limit, $result['total']);
    }

    #[Route('/api/v1/stock/items/{itemType}/{itemId}', name: 'api_stock_item', requirements: ['itemType' => 'INGREDIENT|PRODUCT', 'itemId' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('inventory.read')]
    public function item(string $itemType, string $itemId): JsonResponse
    {
        $result = $this->queries->item(StockItemType::from($itemType), Uuid::fromString($itemId));

        $total = '0';
        $balances = [];
        foreach ($result['balances'] as $row) {
            $total = bcadd($total, $row['quantity'], 6);
            $balances[] = ['location' => $this->serializer->location($row['location']), 'quantity' => InventorySerializer::decimal($row['quantity'])];
        }

        return ApiResponse::data([
            ...$this->serializer->item($result['item']),
            'stockTracked' => $result['item']->stockTracked,
            'balances' => $balances,
            'totalQuantity' => InventorySerializer::decimal($total),
            'hasMovements' => $result['hasMovements'],
        ]);
    }

    #[Route('/api/v1/stock/items/{itemType}/{itemId}/movements', name: 'api_stock_item_movements', requirements: ['itemType' => 'INGREDIENT|PRODUCT', 'itemId' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('inventory.read')]
    public function movements(string $itemType, string $itemId, Request $request): JsonResponse
    {
        $pagination = Pagination::fromRequest($request);
        $result = $this->queries->movements(
            StockItemType::from($itemType),
            Uuid::fromString($itemId),
            $this->uuidQuery($request, 'locationId'),
            $pagination->offset(),
            $pagination->limit,
        );

        return ApiResponse::page(
            array_map(fn (array $row) => $this->serializer->movement($row['movement'], $row['user'], $row['reversed']), $result['items']),
            $pagination->page,
            $pagination->limit,
            $result['total'],
        );
    }

    #[Route('/api/v1/stock/opening-balances', name: 'api_stock_opening_balance', methods: ['POST'])]
    #[IsGranted('inventory.write')]
    public function openingBalance(#[MapRequestPayload] MovementRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->serializer->movement($this->ledger->recordOpeningBalance($payload->toInput())), 201);
    }

    #[Route('/api/v1/stock/adjustments', name: 'api_stock_adjustment', methods: ['POST'])]
    #[IsGranted('inventory.write')]
    public function adjustment(#[MapRequestPayload(validationGroups: ['Default', 'adjustment'])] MovementRequest $payload): JsonResponse
    {
        $movement = $this->ledger->recordAdjustment(MovementType::from((string) $payload->type), $payload->toInput());

        return ApiResponse::data($this->serializer->movement($movement), 201);
    }

    #[Route('/api/v1/stock/movements/{id}/reversal', name: 'api_stock_reversal', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('inventory.reverse')]
    public function reverse(string $id, #[MapRequestPayload] ReversalRequest $payload): JsonResponse
    {
        return ApiResponse::data($this->serializer->movement($this->ledger->reverse(Uuid::fromString($id), $payload->reason)), 201);
    }

    private function uuidQuery(Request $request, string $name): ?Uuid
    {
        $value = $request->query->getString($name);
        if ('' === $value) {
            return null;
        }

        return Uuid::isValid($value) ? Uuid::fromString($value) : throw DomainException::invalidField($name, 'Identificador inválido.');
    }
}
