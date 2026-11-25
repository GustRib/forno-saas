<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Domain\MovementType;
use PHPUnit\Framework\TestCase;

final class MovementTypeTest extends TestCase
{
    public function testDirectionMatchesDocumentedSemantics(): void
    {
        $inbound = array_filter(MovementType::cases(), static fn (MovementType $t) => $t->isInbound());

        self::assertEqualsCanonicalizing(
            [MovementType::OPENING_BALANCE, MovementType::PURCHASE_RECEIPT, MovementType::PRODUCTION_OUTPUT, MovementType::ADJUSTMENT_IN, MovementType::RETURN_IN],
            array_values($inbound),
        );
        self::assertFalse(MovementType::WASTE->isInbound());
        self::assertFalse(MovementType::SALE->isInbound());
    }

    public function testReasonIsRequiredForLossesAdjustmentsReturnsAndReversals(): void
    {
        foreach ([MovementType::WASTE, MovementType::ADJUSTMENT_IN, MovementType::ADJUSTMENT_OUT, MovementType::RETURN_IN, MovementType::REVERSAL] as $type) {
            self::assertTrue($type->requiresReason(), $type->value);
        }
        self::assertFalse(MovementType::OPENING_BALANCE->requiresReason());
    }

    public function testOnlyManualMovementsAreReversibleFromInventory(): void
    {
        self::assertTrue(MovementType::OPENING_BALANCE->isManuallyReversible());
        self::assertTrue(MovementType::WASTE->isManuallyReversible());
        self::assertFalse(MovementType::REVERSAL->isManuallyReversible());
        self::assertFalse(MovementType::SALE->isManuallyReversible());
        self::assertFalse(MovementType::PRODUCTION_CONSUMPTION->isManuallyReversible());
    }

    public function testAdjustmentEndpointTypes(): void
    {
        self::assertSame(
            [MovementType::WASTE, MovementType::ADJUSTMENT_IN, MovementType::ADJUSTMENT_OUT, MovementType::RETURN_IN],
            MovementType::ADJUSTMENT_TYPES,
        );
    }
}
