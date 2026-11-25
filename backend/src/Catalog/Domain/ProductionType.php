<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

enum ProductionType: string
{
    /** Fabricado na padaria (terá ficha técnica). */
    case MANUFACTURED = 'MANUFACTURED';
    /** Comprado pronto para revenda. */
    case RESALE = 'RESALE';
    /** Serviço, sem controle de estoque. */
    case SERVICE = 'SERVICE';
}
