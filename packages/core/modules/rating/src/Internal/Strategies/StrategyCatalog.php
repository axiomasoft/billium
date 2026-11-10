<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Strategies;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\PricingStrategy;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Errors\RatingProblems;
use Billium\Rating\Internal\Math\Dec;
use InvalidArgumentException;

final class StrategyCatalog implements PricingStrategyRegistry
{
    /** @var array<string, PricingStrategy> */
    private array $strategies = [];

    /** @param iterable<PricingStrategy>|null $strategies Trusted host code only (D55/D56). */
    public function __construct(?iterable $strategies = null)
    {
        foreach ($strategies ?? self::builtins() as $strategy) {
            $key = $strategy->id()->value.'@'.$strategy->version();
            if ($strategy->version() < 1 || isset($this->strategies[$key])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.strategy_duplicate_or_invalid');
            }
            $this->strategies[$key] = $strategy;
        }
    }

    /** @return list<PricingStrategy> */
    public static function builtins(): array
    {
        return [new FixedStrategy, new PerUnitStrategy, new GraduatedStrategy, new VolumeStrategy];
    }

    public function resolve(StrategyId $id, int $version): PricingStrategy
    {
        return $this->strategies[$id->value.'@'.$version]
            ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.strategy_unknown');
    }

    public function validate(PriceVersion $version): void
    {
        $this->resolve(id: $version->strategyId, version: $version->strategyVersion);
        if ($version->sellerRef === '' || $version->priceRef === '' || $version->version < 1 || preg_match('/^[A-Z]{3}$/D', $version->currency) !== 1 || $version->currencyExponent < 0 || $version->currencyExponent > 4 || $version->unit === '' || $version->exponentVersion === '' || $version->roundingPolicyVersion === '' || $version->termsDigest === '') {
            throw RatingProblems::invalid('invalid price metadata');
        }
        if ($version->exponentVersion !== 'ISO4217-2026-10') {
            throw RatingProblems::invalid('unknown currency exponent version');
        }
        try {
            $currency = Currency::of($version->currency);
        } catch (ProblemException) {
            throw RatingProblems::invalid('unknown currency');
        }
        if ($currency->scale !== $version->currencyExponent) {
            throw RatingProblems::invalid('currency exponent mismatch');
        }
        $rules = $version->quantityRules;
        if ($rules->quantityScale < 0 || $rules->quantityScale > 12 || preg_match('/^[1-9][0-9]*$/D', $rules->conversionNumerator) !== 1 || preg_match('/^[1-9][0-9]*$/D', $rules->conversionDenominator) !== 1) {
            throw RatingProblems::invalid('invalid quantity rules');
        }
        try {
            $increment = Dec::parse($rules->billingIncrement);
            if ($increment->sign() <= 0 || $increment->scale > $rules->quantityScale || ($rules->incrementMode === IncrementMode::None && $increment->cmp(Dec::parse('1')) !== 0)) {
                throw RatingProblems::invalid('invalid billing increment');
            }
            $terms = $version->terms;
            if ($version->strategyId === StrategyId::Fixed && $terms instanceof FixedTerms) {
                if ($terms->scope !== 'per_component_occurrence') {
                    throw RatingProblems::unsupported('fixed scope');
                }
                $this->nonnegative($terms->amount);
            } elseif ($version->strategyId === StrategyId::PerUnit && $terms instanceof PerUnitTerms) {
                $this->nonnegative($terms->unitPrice);
            } elseif (($version->strategyId === StrategyId::TieredGraduated && $terms instanceof GraduatedTerms) || ($version->strategyId === StrategyId::TieredVolume && $terms instanceof VolumeTerms)) {
                $this->tiers($terms->tiers);
            } else {
                throw RatingProblems::invalid('strategy and terms mismatch');
            }
        } catch (InvalidArgumentException) {
            throw RatingProblems::invalid('invalid decimal');
        }
        if (! hash_equals(CanonicalJson::termsDigest($version), $version->termsDigest)) {
            throw RatingProblems::invalid('terms digest mismatch');
        }
        $commitment = $version->commitment;
        if ($commitment === null) {
            return;
        }
        if ($commitment->minimumMinor === null && $commitment->maximumMinor === null || $commitment->minimumMinor !== null && $commitment->minimumMinor < 0 || $commitment->maximumMinor !== null && $commitment->maximumMinor < 0 || $commitment->minimumMinor !== null && $commitment->maximumMinor !== null && $commitment->minimumMinor > $commitment->maximumMinor) {
            throw RatingProblems::invalid('invalid monetary bounds');
        }
        if ($commitment->sellerRef !== $version->sellerRef || $commitment->currency !== $version->currency || $commitment->componentRef === '' || $commitment->billingAccountRef === '' || $commitment->periodRef === '' || ! $commitment->consumedByAlreadyBilled || $commitment->appliesAt !== 'period_final') {
            throw RatingProblems::unsupported('commitment scope or currency');
        }
    }

    private function nonnegative(string $value): Dec
    {
        $decimal = Dec::parse($value);
        if ($decimal->scale > 12 || $decimal->sign() < 0) {
            throw RatingProblems::invalid('invalid non-negative amount');
        }

        return $decimal;
    }

    /** @param array<array-key, mixed> $tiers */
    private function tiers(array $tiers): void
    {
        if ($tiers === [] || ! array_is_list($tiers)) {
            throw RatingProblems::invalid('tiers must be a non-empty list');
        }
        $next = Dec::parse('0');
        foreach ($tiers as $index => $tier) {
            if (! $tier instanceof Tier || $tier->index !== $index || $next === null || $this->nonnegative($tier->lower)->cmp($next) !== 0) {
                throw RatingProblems::invalid('non-contiguous tiers');
            }
            $this->nonnegative($tier->unitPrice);
            $this->nonnegative($tier->flatFee);
            $next = $tier->upper === null ? null : $this->nonnegative($tier->upper);
            if ($next !== null && $next->cmp(Dec::parse($tier->lower)) <= 0) {
                throw RatingProblems::invalid('invalid tier upper bound');
            }
        }
        if ($next !== null) {
            throw RatingProblems::invalid('last tier must be unbounded');
        }
    }
}
