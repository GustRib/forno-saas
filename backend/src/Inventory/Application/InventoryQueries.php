<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Catalog\Application\StockItemCatalog;
use App\Catalog\Application\StockItemView;
use App\Catalog\Domain\StockItemType;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\UserRepository;
use App\Inventory\Domain\Model\StockLocation;
use App\Inventory\Domain\Model\StockMovement;
use App\Inventory\Infrastructure\StockRepository;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Symfony\Component\Uid\Uuid;

/**
 * Consultas de estoque (FNO-032): saldo por item e local, e histórico.
 * O saldo vem de stock_balances, que é a soma do ledger mantida pelo banco.
 */
final class InventoryQueries
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StockRepository $repository,
        private readonly StockItemCatalog $catalog,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<StockLocation>
     */
    public function locations(): array
    {
        return $this->repository->listLocations($this->context->getTenantId());
    }

    /**
     * Itens estocáveis com saldo no local (padrão se não informado).
     *
     * @return array{location: StockLocation, rows: list<array{item: StockItemView, quantity: string}>, total: int}
     */
    public function overview(StockItemType $type, ?string $q, ?Uuid $locationId, bool $activeOnly, int $offset, int $limit): array
    {
        $tenantId = $this->context->getTenantId();
        $location = $this->location($tenantId, $locationId);

        $page = $this->catalog->search($tenantId, $type, $q, $activeOnly, $offset, $limit);
        $balances = $this->repository->balancesFor($tenantId, $type, array_map(static fn (StockItemView $i) => $i->id, $page['items']), $location->getId());

        $rows = array_map(static fn (StockItemView $item) => [
            'item' => $item,
            'quantity' => $balances[$item->id->toRfc4122()] ?? '0',
        ], $page['items']);

        return ['location' => $location, 'rows' => $rows, 'total' => $page['total']];
    }

    /**
     * @return array{item: StockItemView, balances: list<array{location: StockLocation, quantity: string}>, hasMovements: bool}
     */
    public function item(StockItemType $type, Uuid $itemId): array
    {
        $tenantId = $this->context->getTenantId();
        $item = $this->catalog->find($tenantId, $type, $itemId) ?? throw DomainException::notFound('Item não encontrado.');

        $byLocation = [];
        foreach ($this->repository->balancesByLocation($tenantId, $type, $itemId) as $row) {
            $byLocation[$row['locationId']] = (string) $row['quantity'];
        }

        $balances = [];
        foreach ($this->repository->listLocations($tenantId) as $location) {
            $balances[] = ['location' => $location, 'quantity' => $byLocation[$location->getId()->toRfc4122()] ?? '0'];
        }

        return [
            'item' => $item,
            'balances' => $balances,
            'hasMovements' => $this->repository->hasMovements($tenantId, $type, $itemId),
        ];
    }

    /**
     * @return array{items: list<array{movement: StockMovement, user: ?User, reversed: bool}>, total: int}
     */
    public function movements(StockItemType $type, Uuid $itemId, ?Uuid $locationId, int $offset, int $limit): array
    {
        $tenantId = $this->context->getTenantId();
        if (null === $this->catalog->find($tenantId, $type, $itemId)) {
            throw DomainException::notFound('Item não encontrado.');
        }

        $page = $this->repository->movementsFor($tenantId, $type, $itemId, $locationId, $offset, $limit);
        $userIds = array_unique(array_map(static fn (StockMovement $m) => $m->getCreatedBy()->toRfc4122(), $page['items']));
        $users = $this->users->findByIds(array_map(static fn (string $id) => Uuid::fromString($id), array_values($userIds)));
        $reversed = $this->repository->reversedAmong($tenantId, array_map(static fn (StockMovement $m) => $m->getId(), $page['items']));

        return [
            'items' => array_map(static fn (StockMovement $m) => [
                'movement' => $m,
                'user' => $users[$m->getCreatedBy()->toRfc4122()] ?? null,
                'reversed' => isset($reversed[$m->getId()->toRfc4122()]),
            ], $page['items']),
            'total' => $page['total'],
        ];
    }

    private function location(Uuid $tenantId, ?Uuid $locationId): StockLocation
    {
        $location = null !== $locationId
            ? $this->repository->findLocation($tenantId, $locationId)
            : $this->repository->defaultLocation($tenantId);

        return $location ?? throw DomainException::notFound('Local de estoque não encontrado.');
    }
}
