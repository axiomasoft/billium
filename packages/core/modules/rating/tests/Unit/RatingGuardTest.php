<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Pricing\CommitmentBasis;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Corrections\DeltaCalculator;
use Billium\Rating\Internal\Math\Dec;
use Billium\Rating\Internal\Money\MoneyFactory;
use Billium\Rating\Internal\Proration\ProrationCalculator;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Rating\Internal\Tax\TaxCalculator;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Price metadata, quantities, money, proration and tax inputs refuse values that would misprice a line. */
final class RatingGuardTest extends TestCase
{
    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function refused(): iterable
    {
        $validate = /** @param array<string, mixed> $overrides */ static function (array $overrides): PriceVersion {
            $price = self::price($overrides);
            (new StrategyCatalog)->validate($price);

            return $price;
        };
        $rules = static fn (int $scale, string $increment = '1', IncrementMode $mode = IncrementMode::None): QuantityRules => new QuantityRules(quantityScale: $scale, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: $increment, incrementMode: $mode);
        $commitment = static fn (?int $minimum = 100, ?int $maximum = 500, string $currency = 'USD', bool $consumed = true): CommitmentTerms => new CommitmentTerms(sellerRef: 'seller-a',
            billingAccountRef: 'customer', componentRef: 'api', currency: $currency, periodRef: 'p1', minimumMinor: $minimum, maximumMinor: $maximum, consumedByAlreadyBilled: $consumed, appliesAt: 'period_final');
        $priced = static fn (PriceVersion $price, string $quantity, ?CommitmentBasis $basis = null, StrategyId $strategy = StrategyId::PerUnit): mixed => (new StrategyCatalog)->resolve(id: $strategy, version: 1)->price(new PricingInput(price: $price, quantity: $quantity, commitmentBasis: $basis));
        $invalid = 'billium.rating.terms_invalid';

        yield 'empty price ref' => [static fn (): mixed => $validate(['priceRef' => '']), $invalid.': invalid price metadata'];
        yield 'lowercase currency' => [static fn (): mixed => $validate(['currency' => 'usd']), $invalid.': invalid price metadata'];
        yield 'unknown exponent table' => [static fn (): mixed => $validate(['exponentVersion' => 'ISO4217-2020-01']), $invalid.': unknown currency exponent version'];
        yield 'exponent differing from the currency' => [static fn (): mixed => $validate(['currencyExponent' => 3]), $invalid.': currency exponent mismatch'];
        yield 'negative quantity scale' => [static fn (): mixed => $validate(['quantityRules' => $rules(-1)]), $invalid.': invalid quantity rules'];
        yield 'quantity scale above twelve' => [static fn (): mixed => $validate(['quantityRules' => $rules(13)]), $invalid.': invalid quantity rules'];
        yield 'increment finer than the quantity scale' => [static fn (): mixed => $validate(['quantityRules' => $rules(0, '0.5', IncrementMode::Ceil)]), $invalid.': invalid billing increment'];
        yield 'fixed price outside one occurrence' => [static fn (): mixed => $validate(['strategyId' => StrategyId::Fixed, 'terms' => new FixedTerms(amount: '1', scope: 'per_invoice')]), 'billium.rating.unsupported_combination: fixed scope'];
        yield 'unit price beyond twelve decimals' => [static fn (): mixed => $validate(['terms' => new PerUnitTerms(unitPrice: '0.0000000000001')]), $invalid.': invalid non-negative amount'];
        yield 'commitment without bounds' => [static fn (): mixed => $validate(['commitment' => $commitment(null, null)]), $invalid.': invalid monetary bounds'];
        yield 'negative commitment minimum' => [static fn (): mixed => $validate(['commitment' => $commitment(-1)]), $invalid.': invalid monetary bounds'];
        yield 'negative commitment maximum' => [static fn (): mixed => $validate(['commitment' => $commitment(null, -1)]), $invalid.': invalid monetary bounds'];
        yield 'commitment in another currency' => [static fn (): mixed => $validate(['commitment' => $commitment(currency: 'EUR')]), 'billium.rating.unsupported_combination: commitment scope or currency'];
        yield 'commitment not consumed by billed usage' => [static fn (): mixed => $validate(['commitment' => $commitment(consumed: false)]), 'billium.rating.unsupported_combination: commitment scope or currency'];
        yield 'price of another strategy' => [static fn (): mixed => $priced(self::price([]), '1', strategy: StrategyId::Fixed), $invalid.': strategy mismatch'];
        yield 'quantity finer than the quantity scale' => [static fn (): mixed => $priced(self::price([]), '1.0001'), $invalid.': quantity scale exceeded'];
        yield 'negative billed commitment basis' => [static fn (): mixed => $priced(self::price(['commitment' => $commitment()]), '1', new CommitmentBasis(alreadyBilledNetMinor: '-1', basisDigest: 'frozen')), $invalid.': frozen commitment basis required'];
        yield 'minor amount in a foreign exponent' => [static fn (): mixed => MoneyFactory::fromMinor('100', 'USD', 3), $invalid.': invalid money'];
        yield 'fractional minor amount' => [static fn (): mixed => MoneyFactory::fromMinor('1.5', 'USD', 2), $invalid.': invalid money'];
        yield 'negative zero minor amount' => [static fn (): mixed => MoneyFactory::fromMinor('-0', 'USD', 2), $invalid.': invalid money'];
        yield 'minor amount below int64' => [static fn (): mixed => MoneyFactory::fromMinor('-9223372036854775809', 'USD', 2), $invalid.': invalid money'];
        yield 'minor amount above int64' => [static fn (): mixed => MoneyFactory::fromMinor('9223372036854775808', 'USD', 2), $invalid.': invalid money'];
        yield 'fractional correction amount' => [static fn (): mixed => DeltaCalculator::delta(['net' => '1.5', 'tax' => '0', 'gross' => '1.5'], ['net' => '2', 'tax' => '0', 'gross' => '2']), $invalid.': non-integer amount'];
        yield 'correction gross differing from net plus tax' => [static fn (): mixed => DeltaCalculator::delta(['net' => '1', 'tax' => '1', 'gross' => '3'], ['net' => '2', 'tax' => '0', 'gross' => '2']), $invalid.': gross mismatch'];
        yield 'fractional proration total' => [static fn (): mixed => (new ProrationCalculator)->prorateMinor(Dec::parse('10'), 2, '1', '1.5', 'half_up'), $invalid.': invalid proration fraction'];
        yield 'empty proration period' => [static fn (): mixed => (new ProrationCalculator)->prorateMinor(Dec::parse('10'), 2, '0', '0', 'half_up'), $invalid.': invalid proration fraction'];
        yield 'elapsed beyond the period' => [static fn (): mixed => (new ProrationCalculator)->prorateMinor(Dec::parse('10'), 2, '2', '1', 'half_up'), $invalid.': invalid proration fraction'];
        yield 'tax rate beyond eight decimals' => [static fn (): mixed => (new TaxCalculator)->calculate(['line' => '10'], 'exclusive', '0.123456789', 2, 'half_up'), $invalid];
        yield 'no-tax reason on a taxed line' => [static fn (): mixed => (new TaxCalculator)->calculate(['line' => '10'], 'exclusive', '0.2', 2, 'half_up', 'exempt'), $invalid];
    }

    /** @param Closure(): mixed $call */
    #[DataProvider('refused')]
    public function test_a_mispricing_input_is_refused(Closure $call, string $detail): void
    {
        try {
            $call();
            self::fail('mispricing input accepted');
        } catch (ProblemException $e) {
            self::assertSame($detail, $e->detail);
        }
    }

    public function test_an_unscaled_decimal_must_be_an_integer_at_a_non_negative_scale(): void
    {
        foreach ([['1', -1], ['1.5', 1]] as [$unscaled, $scale]) {
            try {
                Dec::ofUnscaled($unscaled, $scale);
                self::fail("{$unscaled}@{$scale} accepted");
            } catch (InvalidArgumentException $e) {
                self::assertSame('Invalid unscaled decimal', $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private static function price(array $overrides): PriceVersion
    {
        $args = [...['sellerRef' => 'seller-a', 'priceRef' => 'price', 'version' => 1, 'strategyId' => StrategyId::PerUnit, 'strategyVersion' => 1, 'currency' => 'USD', 'currencyExponent' => 2,
            'exponentVersion' => 'ISO4217-2026-10', 'unit' => 'call', 'quantityRules' => new QuantityRules(quantityScale: 3, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None),
            'terms' => new PerUnitTerms(unitPrice: '1'), 'roundingMode' => RoundingMode::HalfUp, 'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'v1', 'commitment' => null], ...$overrides];
        $draft = new PriceVersion(...[...$args, 'termsDigest' => 'draft']);

        return new PriceVersion(...[...$args, 'termsDigest' => CanonicalJson::termsDigest($draft)]);
    }
}
