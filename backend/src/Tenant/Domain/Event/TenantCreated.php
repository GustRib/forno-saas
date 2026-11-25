<?php

declare(strict_types=1);

namespace App\Tenant\Domain\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Disparado de forma síncrona, dentro da transação de criação do
 * estabelecimento. Outros módulos (ex.: Catalog) reagem criando dados
 * iniciais sem que o módulo Tenant precise conhecê-los.
 */
final readonly class TenantCreated
{
    public function __construct(
        public Uuid $tenantId,
        public Uuid $ownerUserId,
    ) {
    }
}
