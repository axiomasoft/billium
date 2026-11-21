<?php

declare(strict_types=1);

namespace Billium\Platform\Tests;

use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Internal\Accounting\AccountingFactMapper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class HardeningAccountingFactMapperTest extends TestCase
{
    public function test_partial_reversal_nets_the_sign_flipped_charge_against_the_remainder(): void
    {
        $mapper = new AccountingFactMapper;
        $at = new DateTimeImmutable('2026-11-21T00:00:00Z');
        $applied = $mapper->map($this->record($at, 1, [
            'receipt_ref' => 'allocation:a1',
            'obligation_ref' => 'o1',
            'amount_minor' => '10000',
            'currency' => 'RUB',
        ]));
        $payload = [
            'receipt_ref' => 'reversal:r1',
            'reverses_receipt_ref' => 'allocation:a1',
            'obligation_ref' => 'o1',
            'amount_minor' => '-10000',
            'remainder_minor' => '6000',
            'currency' => 'RUB',
            'cause' => 'customer_adjustment',
        ];
        $reversal = $mapper->map($this->record($at, 2, $payload));
        $replay = $mapper->map($this->record($at, 3, $payload, 'delivery-2'));

        self::assertSame(AccountingMappingStatus::Mapped, $applied->status);
        self::assertSame(AccountingMappingStatus::Mapped, $reversal->status);
        self::assertNotNull($applied->fact);
        self::assertNotNull($reversal->fact);
        self::assertNotNull($replay->fact);
        self::assertSame(AccountingFactKind::AllocationApplied, $applied->fact->factKind);
        self::assertSame(AccountingFactKind::AllocationReversed, $reversal->fact->factKind);
        self::assertSame(10000, $applied->fact->money->minor);
        self::assertSame(4000, $reversal->fact->money->minor);
        self::assertSame('40.00', $reversal->fact->money->amount());
        self::assertSame(6000, $applied->fact->money->minor - $reversal->fact->money->minor);
        self::assertSame($applied->fact->sourceFactRef, $reversal->fact->originalFactRef);
        self::assertNotSame($applied->fact->sourceFactRef, $reversal->fact->sourceFactRef);
        self::assertSame($reversal->fact->sourceFactRef, $replay->fact->sourceFactRef);
        self::assertSame($reversal->fact->money->minor, $replay->fact->money->minor);
    }

    public function test_full_reversal_keeps_the_charge_when_the_remainder_is_zero(): void
    {
        $mapper = new AccountingFactMapper;
        $reversal = $mapper->map($this->record(new DateTimeImmutable('2026-11-21T00:00:00Z'), 2, [
            'receipt_ref' => 'reversal:r5',
            'reverses_receipt_ref' => 'allocation:a2',
            'amount_minor' => '-4000',
            'remainder_minor' => '0',
            'currency' => 'RUB',
        ]));

        self::assertSame(AccountingMappingStatus::Mapped, $reversal->status);
        self::assertNotNull($reversal->fact);
        self::assertSame(AccountingFactKind::AllocationReversed, $reversal->fact->factKind);
        self::assertSame(4000, $reversal->fact->money->minor);
    }

    public function test_out_of_range_minor_string_is_not_saturated_through_float(): void
    {
        $mapper = new AccountingFactMapper;
        $at = new DateTimeImmutable('2026-11-21T00:00:00Z');
        $saturated = $mapper->map($this->record($at, 1, [
            'receipt_ref' => 'allocation:overflow',
            'amount_minor' => '9223372036854775808',
            'currency' => 'RUB',
        ]));
        $limit = $mapper->map($this->record($at, 1, [
            'receipt_ref' => 'allocation:limit',
            'amount_minor' => '9223372036854775807',
            'currency' => 'RUB',
        ]));
        $floatLine = $mapper->map(new AccountingSourceRecord(
            'billium.event.v1',
            'credit_note.issued.v1',
            'inst-test',
            'seller-a',
            'test',
            'ledger:core',
            'inv-1',
            2,
            $at,
            [
                'credit_note_ref' => 'cn-float',
                'invoice_ref' => 'inv-1',
                'currency' => 'RUB',
                'lines' => [
                    ['amount_minor' => '9223372036854775807'],
                    ['amount_minor' => '1'],
                ],
            ],
        ));

        self::assertSame(AccountingMappingStatus::IgnoredNonMonetary, $saturated->status);
        self::assertNull($saturated->fact);
        self::assertSame(AccountingMappingStatus::Mapped, $limit->status);
        self::assertSame(9223372036854775807, $limit->fact?->money->minor);
        self::assertSame(AccountingMappingStatus::IgnoredNonMonetary, $floatLine->status);
        self::assertNull($floatLine->fact);
    }

    public function test_reversal_with_an_unrepresentable_positive_magnitude_does_not_map(): void
    {
        $mapper = new AccountingFactMapper;
        foreach ([['amount_minor' => (string) PHP_INT_MIN, 'currency' => 'RUB'],
            ['money' => ['amount' => '-92233720368547758.08', 'currency' => 'RUB']]] as $money) {
            $result = $mapper->map($this->record(new DateTimeImmutable('2026-11-21T00:00:00Z'), 2, $money + [
                'receipt_ref' => 'reversal:r1',
                'reverses_receipt_ref' => 'allocation:a1',
            ]));

            self::assertSame(AccountingMappingStatus::IgnoredNonMonetary, $result->status);
            self::assertNull($result->fact);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(DateTimeImmutable $at, int $revision, array $payload, ?string $deliveryAttemptRef = null): AccountingSourceRecord
    {
        return new AccountingSourceRecord(
            'billium.event.v1',
            'allocation.applied.v1',
            'inst-test',
            'seller-a',
            'test',
            'ledger:core',
            'o1',
            $revision,
            $at,
            $payload,
            $deliveryAttemptRef,
        );
    }
}
