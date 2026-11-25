<?php

declare(strict_types=1);

namespace App\Tenant\Presentation\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class SelectTenantRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid(message: 'Identificador inválido.')]
        public readonly string $tenantId = '',
    ) {
    }
}
