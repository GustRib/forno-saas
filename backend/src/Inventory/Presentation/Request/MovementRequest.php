<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Request;

use App\Catalog\Domain\StockItemType;
use App\Inventory\Application\MovementInput;
use App\Shared\Domain\DomainException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corpo de POST /stock/opening-balances e /stock/adjustments.
 * `type` só é usado (e exigido) em ajustes.
 */
final class MovementRequest
{
    private const QTY = '/^\d{1,12}(\.\d{1,6})?$/';

    public function __construct(
        #[Assert\NotBlank(message: 'Selecione o tipo do item.')]
        #[Assert\Choice(choices: ['INGREDIENT', 'PRODUCT'], message: 'Tipo de item inválido.')]
        public readonly string $itemType = '',

        #[Assert\NotBlank(message: 'Selecione o item.')]
        #[Assert\Uuid(message: 'Item inválido.')]
        public readonly string $itemId = '',

        #[Assert\NotBlank(message: 'Informe a quantidade.')]
        #[Assert\Regex(pattern: self::QTY, message: 'Informe uma quantidade válida (até 6 casas decimais).')]
        public readonly string $quantity = '',

        #[Assert\NotBlank(message: 'Selecione o tipo de lançamento.', groups: ['adjustment'])]
        #[Assert\Choice(choices: ['WASTE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'RETURN_IN'], message: 'Tipo de lançamento inválido.', groups: ['adjustment'])]
        public readonly ?string $type = null,

        #[Assert\Uuid(message: 'Unidade inválida.')]
        public readonly ?string $unitId = null,

        #[Assert\Uuid(message: 'Local inválido.')]
        public readonly ?string $locationId = null,

        #[Assert\NotBlank(message: 'Informe o motivo.', groups: ['adjustment'])]
        #[Assert\Length(max: 255)]
        public readonly ?string $reason = null,

        #[Assert\Regex(pattern: self::QTY, message: 'Informe um custo válido (até 6 casas decimais).')]
        public readonly ?string $unitCost = null,

        public readonly ?string $occurredAt = null,
    ) {
    }

    public function toInput(): MovementInput
    {
        return new MovementInput(
            itemType: StockItemType::from($this->itemType),
            itemId: Uuid::fromString($this->itemId),
            quantity: $this->quantity,
            unitId: self::uuid($this->unitId),
            locationId: self::uuid($this->locationId),
            reason: $this->reason,
            unitCost: null === $this->unitCost || '' === $this->unitCost ? null : $this->unitCost,
            occurredAt: $this->parseOccurredAt(),
        );
    }

    private function parseOccurredAt(): ?\DateTimeImmutable
    {
        if (null === $this->occurredAt || '' === $this->occurredAt) {
            return null;
        }

        foreach ([\DATE_ATOM, 'Y-m-d\TH:i:s.vP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $this->occurredAt);
            // createFromFormat aceita "31/02" e "25:00" rolando para outro dia,
            // registrando só um aviso: isso é data inválida, não outra data.
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $date && (false === $errors || 0 === $errors['warning_count'] + $errors['error_count'])) {
                return $date;
            }
        }

        throw DomainException::invalidField('occurredAt', 'Data inválida. Use o formato ISO 8601 com fuso (ex.: 2026-11-25T08:00:00-03:00).');
    }

    private static function uuid(?string $value): ?Uuid
    {
        return null === $value || '' === $value ? null : Uuid::fromString($value);
    }
}
