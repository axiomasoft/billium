<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Unit;

use Billium\Ledger\Internal\InvoiceStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** FSM table of the invoice (p3-ledger-design invoice-fsm): pure oracle, no database. */
final class InvoiceStatusTest extends TestCase
{
    /** @return iterable<string, array{InvoiceStatus, int, int, InvoiceStatus}> */
    public static function derivations(): iterable
    {
        foreach ([InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid] as $from) {
            yield "{$from->value}: nothing settled" => [$from, 1000, 0, InvoiceStatus::Issued];
            yield "{$from->value}: one minor unit" => [$from, 1000, 1, InvoiceStatus::PartiallyPaid];
            yield "{$from->value}: all but one" => [$from, 1000, 999, InvoiceStatus::PartiallyPaid];
            yield "{$from->value}: fully settled" => [$from, 1000, 1000, InvoiceStatus::Paid];
        }
        yield 'uncollectible stays while nothing is settled' => [InvoiceStatus::Uncollectible, 1000, 0, InvoiceStatus::Uncollectible];
        yield 'late partial payment leaves uncollectible' => [InvoiceStatus::Uncollectible, 1000, 400, InvoiceStatus::PartiallyPaid];
        yield 'late full payment leaves uncollectible' => [InvoiceStatus::Uncollectible, 1000, 1000, InvoiceStatus::Paid];
        yield 'zero total nothing settled stays issued' => [InvoiceStatus::Paid, 0, 0, InvoiceStatus::Issued];
        foreach ([InvoiceStatus::Draft, InvoiceStatus::Void, InvoiceStatus::Discarded] as $fixed) {
            yield "{$fixed->value} never derives" => [$fixed, 1000, 0, $fixed];
            yield "{$fixed->value} never derives a payment" => [$fixed, 1000, 1000, $fixed];
        }
    }

    #[DataProvider('derivations')]
    public function test_derive_table(InvoiceStatus $current, int $total, int $settled, InvoiceStatus $expected): void
    {
        self::assertSame($expected, InvoiceStatus::derive($current, $total, $settled));
    }

    /** @return iterable<string, array{int, int}> */
    public static function impossibleSums(): iterable
    {
        yield 'settled above total' => [1000, 1001];
        yield 'negative settled' => [1000, -1];
        yield 'negative total' => [-1, 0];
    }

    #[DataProvider('impossibleSums')]
    public function test_derive_refuses_sums_the_ledger_cannot_hold(int $total, int $settled): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceStatus::derive(InvoiceStatus::Issued, $total, $settled);
    }

    public function test_transition_table_is_exactly_the_documented_one(): void
    {
        $allowed = [
            'draft' => ['issued', 'discarded'],
            'issued' => ['partially_paid', 'paid', 'uncollectible', 'void'],
            'partially_paid' => ['issued', 'paid', 'uncollectible'],
            'paid' => ['issued', 'partially_paid'],
            'uncollectible' => ['partially_paid', 'paid'],
            'void' => [],
            'discarded' => [],
        ];
        foreach (InvoiceStatus::cases() as $from) {
            $actual = array_map(static fn (InvoiceStatus $to): string => $to->value, array_values(array_filter(InvoiceStatus::cases(), $from->canTransitionTo(...))));
            self::assertSame($allowed[$from->value], $actual, "from {$from->value}");
        }
    }

    public function test_nothing_returns_to_draft_and_terminal_states_stay_terminal(): void
    {
        foreach (InvoiceStatus::cases() as $from) {
            self::assertFalse($from->canTransitionTo(InvoiceStatus::Draft));
            self::assertFalse($from->canTransitionTo($from), 'same status is not a transition');
        }
        foreach ([InvoiceStatus::Void, InvoiceStatus::Discarded] as $terminal) {
            foreach (InvoiceStatus::cases() as $to) {
                self::assertFalse($terminal->canTransitionTo($to));
            }
        }
    }

    public function test_only_draft_is_not_frozen(): void
    {
        foreach (InvoiceStatus::cases() as $status) {
            self::assertSame($status !== InvoiceStatus::Draft, $status->isFrozen());
        }
    }
}
