<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Shared\Domain\DomainException;

final class IncompatibleUnitsException extends DomainException
{
    public function __construct(Dimension $from, Dimension $to)
    {
        parent::__construct(
            \sprintf(
                'Não é possível converter %s em %s. Conversões só são permitidas dentro da mesma dimensão.',
                mb_strtolower($from->label()),
                mb_strtolower($to->label()),
            ),
            'INCOMPATIBLE_UNITS',
            422,
        );
    }
}
