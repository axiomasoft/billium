<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Unit;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Internal\Policy\ProRataOpenPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** pro_rata_open/1 worked examples of the allocation-policy knowledge (D46, SB-07, REQ-ACC-10); no database, no float. */
final class ProRataOpenPolicyTest extends TestCase
{
    /** @return iterable<string, array{int, list<int>, list<int>}> */
    public static function examples(): iterable
    {
        // SB-07: O1 = 66.67, O2 = 33.33; 50.00 → 3333.5 / 1666.5 → floor 3333 + 1666, the tie goes to index 0.
        yield 'SB-07 first payment' => [5000, [6667, 3333], [3334, 1666]];
        // Second payment of 50.00 meets open 3333 and 1667: A = O, both settled.
        yield 'SB-07 second payment' => [5000, [3333, 1667], [3333, 1667]];
        yield 'partial payment rev1' => [3000, [10000], [3000]];
        yield 'partial payment rev2 delta' => [7000, [7000], [7000]];
        yield 'overpayment leaves the excess unallocated' => [12000, [6000, 4000], [6000, 4000]];
        yield 'nothing open allocates nothing' => [5000, [0, 0], [0, 0]];
        yield 'zero open target gets zero' => [100, [0, 300, 100], [0, 75, 25]];
        yield 'zero amount' => [0, [10, 20], [0, 0]];
        yield 'three-way tie remainder to the lowest indexes' => [2, [1, 1, 1], [1, 1, 0]];
        yield 'int64 open amounts' => [10, [PHP_INT_MAX, PHP_INT_MAX], [5, 5]];
    }

    /**
     * @param  list<int>  $open
     * @param  list<int>  $expected
     */
    #[DataProvider('examples')]
    public function test_worked_examples(int $amount, array $open, array $expected): void
    {
        $parts = (new ProRataOpenPolicy)->allocate(new Money($amount, Currency::of('RUB')), $open);
        self::assertSame($expected, array_map(static fn (Money $part): int => $part->minor, $parts));
        // Σ parts = min(A, O): either the whole amount is spread, or every open amount is paid in full.
        $minors = array_map(static fn (Money $part): int => $part->minor, $parts);
        self::assertTrue(array_sum($minors) === $amount || $minors === $open, 'Σ parts = min(A, O)');
        foreach ($parts as $index => $part) {
            self::assertSame('RUB', $part->currency->code);
            self::assertLessThanOrEqual($open[$index], $part->minor);
        }
    }

    public function test_the_tie_break_follows_target_position_not_obligation_identity(): void
    {
        $policy = new ProRataOpenPolicy;
        // Reordering the targets moves the extra minor unit with the position (COV-287).
        self::assertSame([3334, 1666], array_map(static fn (Money $m): int => $m->minor, $policy->allocate(new Money(5000, Currency::of('RUB')), [6667, 3333])));
        self::assertSame([1667, 3333], array_map(static fn (Money $m): int => $m->minor, $policy->allocate(new Money(5000, Currency::of('RUB')), [3333, 6667])));
        self::assertSame('pro_rata_open/1', ProRataOpenPolicy::VERSION);
    }

    /** @return iterable<string, array{int, list<int>}> */
    public static function invalid(): iterable
    {
        yield 'negative amount' => [-1, [10]];
        yield 'no targets' => [10, []];
        yield 'negative open' => [10, [5, -1]];
    }

    /** @param list<int> $open */
    #[DataProvider('invalid')]
    public function test_invalid_input_is_refused(int $amount, array $open): void
    {
        try {
            (new ProRataOpenPolicy)->allocate(new Money($amount, Currency::of('RUB')), $open);
            self::fail('invalid policy input');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }
}
