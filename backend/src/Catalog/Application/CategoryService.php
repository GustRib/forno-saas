<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\CategoryKind;
use App\Catalog\Domain\Model\Category;
use App\Catalog\Infrastructure\CatalogRepository;
use App\Shared\Domain\DomainException;
use App\Tenant\Application\TenantContext;
use Symfony\Component\Uid\Uuid;

final class CategoryService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CatalogRepository $repository,
    ) {
    }

    /**
     * @return list<Category>
     */
    public function list(?CategoryKind $kind = null, ?bool $active = null): array
    {
        return $this->repository->listCategories($this->context->getTenantId(), $kind?->value, $active);
    }

    public function get(Uuid $id): Category
    {
        return $this->repository->findForTenant(Category::class, $this->context->getTenantId(), $id)
            ?? throw DomainException::notFound('Categoria não encontrada.');
    }

    public function create(string $name, CategoryKind $kind, int $sortOrder): Category
    {
        $tenantId = $this->context->getTenantId();
        $this->assertUniqueName($tenantId, $name, $kind);

        $category = new Category($tenantId, $name, $kind, $sortOrder);
        $this->repository->add($category);
        $this->repository->flush();

        return $category;
    }

    public function update(Uuid $id, string $name, CategoryKind $kind, int $sortOrder, bool $active): Category
    {
        $category = $this->get($id);
        $this->assertUniqueName($category->getTenantId(), $name, $kind, $category->getId());

        if ($kind !== $category->getKind() && $this->repository->isCategoryInUse($category->getTenantId(), $category->getId())) {
            throw new DomainException('Não é possível mudar o tipo de uma categoria que já possui itens.', 'CATEGORY_IN_USE');
        }

        $category->update($name, $kind, $sortOrder, $active);
        $this->repository->flush();

        return $category;
    }

    private function assertUniqueName(Uuid $tenantId, string $name, CategoryKind $kind, ?Uuid $exceptId = null): void
    {
        if ($this->repository->existsForTenant(Category::class, $tenantId, ['name' => trim($name), 'kind' => $kind->value], $exceptId)) {
            throw DomainException::conflict('Já existe uma categoria com este nome e tipo.', 'DUPLICATE_CATEGORY');
        }
    }
}
