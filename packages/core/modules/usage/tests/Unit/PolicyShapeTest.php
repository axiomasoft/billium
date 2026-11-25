<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Internal\Math\Decimal;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Aggregation policy, finality contract, fact quantity and decimal scale refuse values the reducers cannot represent. */
final class PolicyShapeTest extends TestCase
{
    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function refused(): iterable
    {
        yield 'negative quantity scale' => [static fn (): mixed => V::policy(['algorithm' => 'additive_sum@1', 'scale' => -1]), 'policy_unknown'];
        yield 'quantity scale above 12' => [static fn (): mixed => V::policy(['algorithm' => 'additive_sum@1', 'scale' => 13]), 'policy_unknown'];
        yield 'fractional distinct count' => [static fn (): mixed => V::policy(['algorithm' => 'distinct_exact_union@1', 'scale' => 2]), 'policy_unknown'];
        yield 'reopen without accept-adjust' => [static fn (): mixed => new SourceFinalityContract('finality', 1, ['s1'], 0, LatePolicy::Reject, true), 'policy_unknown'];
        yield 'decimal fact quantity' => [static fn (): mixed => V::revisions([['fact' => 'f1', 't' => 1, 'q' => '1.5']]), 'fact_invalid'];
        yield 'negative decimal scale' => [static fn (): mixed => Decimal::unscaled('1', -1), 'quantity_invalid'];
        yield 'decimal scale above 12' => [static fn (): mixed => Decimal::unscaled('1', 13), 'quantity_invalid'];
        yield 'digits beyond the scale' => [static fn (): mixed => Decimal::unscaled('1.25', 1), 'quantity_scale_invalid'];
    }

    /** @param Closure(): mixed $make */
    #[DataProvider('refused')]
    public function test_an_unrepresentable_value_is_refused(Closure $make, string $slug): void
    {
        try {
            $make();
            self::fail('unrepresentable value accepted');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::UsageConflict, $e->problem);
            self::assertStringStartsWith('billium.usage.'.$slug, $e->detail);
        }
    }

    public function test_trailing_zeros_beyond_the_scale_are_exact(): void
    {
        self::assertSame('12', Decimal::unscaled('1.20', 1));
        self::assertInstanceOf(FactRevision::class, V::revisions([['fact' => 'f1', 't' => 1, 'q' => '-7']])[0]);
    }
}
