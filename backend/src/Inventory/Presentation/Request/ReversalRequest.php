<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class ReversalRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe o motivo do estorno.')]
        #[Assert\Length(max: 255)]
        public readonly string $reason = '',
    ) {
    }
}
