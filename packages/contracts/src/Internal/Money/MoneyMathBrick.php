<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal\Money;

use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Enums\RoundingMode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Contracts\Internal\Ratios;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode as BrickRounding;
use Brick\Money\AllocationMode;
use Brick\Money\Currency as BrickCurrency;
use Brick\Money\Money as BrickMoney;

/**
 * MoneyMath on brick/money: BigDecimal arithmetic and the library's Hamilton allocation.
 */
final class MoneyMathBrick implements MoneyMath
{
    public function add(Money $a, Money $b): Money
    {
        if ($a->currency->code !== $b->currency->code) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, "{$a->currency->code} and {$b->currency->code} cannot be added");
        }

        return $this->back($this->wrap($a)->plus($this->wrap($b)), $a->currency);
    }

    public function allocate(Money $total, array $ratios): array
    {
        $parts = $this->wrap($total)->allocate(Ratios::parse($ratios), AllocationMode::FloorToLargestRemainder);

        return array_map(fn (BrickMoney $part): Money => $this->back($part, $total->currency), $parts);
    }

    public function round(string $value, Currency $currency, RoundingMode $mode): Money
    {
        RoundInput::parse($value);
        $rounded = BigDecimal::of($value)->toScale(
            $currency->scale,
            $mode === RoundingMode::HalfEven ? BrickRounding::HalfEven : BrickRounding::HalfUp,
        );

        return new Money(Int64::fromString((string) $rounded->getUnscaledValue()), $currency);
    }

    private function wrap(Money $money): BrickMoney
    {
        $currency = new BrickCurrency($money->currency->code, null, $money->currency->code, $money->currency->scale);

        return BrickMoney::ofMinor($money->minor, $currency);
    }

    private function back(BrickMoney $money, Currency $currency): Money
    {
        return new Money(Int64::fromString((string) $money->getMinorAmount()->toBigInteger()), $currency);
    }
}
