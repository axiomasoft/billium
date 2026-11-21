<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Policy;

use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;

/**
 * pro_rata_open/1 (D46, allocation-policy): distributes an amount A over the open amounts of the settlement targets in
 * their `position` order. O = Σ open; O = 0 gives nothing; A ≥ O pays every open amount and leaves A − O unallocated
 * on the payment; A < O uses MoneyMath::allocate (largest remainder, ties to the lower index, D44). Parts sum to
 * min(A, O) exactly and never use floats. A zero part creates no allocation row; that is the caller's choice.
 */
#[Internal]
final readonly class ProRataOpenPolicy
{
    public const string VERSION = 'pro_rata_open/1';

    private MoneyMath $math;

    public function __construct(?MoneyMath $math = null)
    {
        $this->math = $math ?? MoneyMathFactory::create();
    }

    /**
     * @param  list<int>  $open  open_i = due + adjusted − allocated − credited of each target, read under its lock
     * @return list<Money> one part per target, in the same order
     */
    public function allocate(Money $amount, array $open): array
    {
        if ($amount->minor < 0 || $open === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'a nonnegative amount over at least one target');
        }
        $total = 0;
        $exceeds = false;
        foreach ($open as $value) {
            if ($value < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'open amounts are nonnegative minor integers');
            }
            // A sum beyond int64 is certainly above any int64 amount; the pro-rata branch never needs it.
            $exceeds = $exceeds || $total > PHP_INT_MAX - $value;
            $total = $exceeds ? $total : $total + $value;
        }
        $zero = static fn (): Money => new Money(0, $amount->currency);
        if (! $exceeds && $total === 0) {
            return array_map(static fn (): Money => $zero(), $open);
        }
        if (! $exceeds && $amount->minor >= $total) {
            return array_map(static fn (int $value): Money => new Money($value, $amount->currency), $open);
        }
        if ($amount->minor === 0) {
            return array_map(static fn (): Money => $zero(), $open);
        }

        return $this->math->allocate($amount, array_map(static fn (int $value): string => (string) $value, $open));
    }
}
