<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Pricing\CommitmentBasis;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use PHPUnit\Framework\TestCase;

final class PricingStrategyTest extends TestCase
{
    private function price(StrategyId $strategy, PriceTerms $terms, ?CommitmentTerms $commitment = null, ?QuantityRules $rules = null): PriceVersion
    {
        $args = ['sellerRef' => 'seller-a', 'priceRef' => 'price', 'version' => 1, 'strategyId' => $strategy, 'strategyVersion' => 1,
            'currency' => 'USD', 'currencyExponent' => 2, 'exponentVersion' => 'ISO4217-2026-10', 'unit' => 'call',
            'quantityRules' => $rules ?? new QuantityRules(quantityScale: 3, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None),
            'terms' => $terms, 'roundingMode' => RoundingMode::HalfUp, 'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'v1', 'commitment' => $commitment];
        $draft = new PriceVersion(...$args, termsDigest: 'draft');

        return new PriceVersion(...$args, termsDigest: CanonicalJson::termsDigest($draft));
    }

    private function priced(PriceVersion $price, string $quantity, ?CommitmentBasis $basis = null): string
    {
        $strategy = (new StrategyCatalog)->resolve(id: $price->strategyId, version: $price->strategyVersion);

        return $strategy->price(new PricingInput(price: $price, quantity: $quantity, commitmentBasis: $basis))->pricedAmount;
    }

    private function assertAmount(string $expected, string $actual): void
    {
        self::assertSame(0, bccomp($expected, $actual, 12), "Expected {$expected}, got {$actual}");
    }

    public function test_pricing_strategy_fixed_per_unit_and_conversion(): void
    {
        $fixed = $this->price(StrategyId::Fixed, new FixedTerms(amount: '49.00', scope: 'per_component_occurrence'));
        foreach (['0', '1000000'] as $quantity) {
            $this->assertAmount('49.00', $this->priced($fixed, $quantity));
        }
        $unit = $this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '0.0125'));
        $this->assertAmount('12.5', $this->priced($unit, '1000'));
        $this->assertAmount('15.43125', $this->priced($unit, '1234.5'));
        $ceil = $this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'), rules: new QuantityRules(quantityScale: 1, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '0.5', incrementMode: IncrementMode::Ceil));
        $this->assertAmount('1.5', $this->priced($ceil, '1.2'));
        $converted = $this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'), rules: new QuantityRules(quantityScale: 1, conversionNumerator: '3', conversionDenominator: '2', billingIncrement: '1', incrementMode: IncrementMode::None));
        $this->assertAmount('3', $this->priced($converted, '2'));
    }

    public function test_pricing_strategy_graduated_and_volume_boundaries(): void
    {
        $tiers = [new Tier(index: 0, lower: '0', upper: '100', unitPrice: '1', flatFee: '0'), new Tier(index: 1, lower: '100', upper: null, unitPrice: '0.8', flatFee: '0')];
        $graduated = $this->price(StrategyId::TieredGraduated, new GraduatedTerms(tiers: $tiers));
        foreach (['0' => '0', '99.999' => '99.999', '100' => '100', '150' => '140'] as $q => $expected) {
            $this->assertAmount($expected, $this->priced($graduated, (string) $q));
        }
        $volume = $this->price(StrategyId::TieredVolume, new VolumeTerms(tiers: [new Tier(index: 0, lower: '0', upper: '150', unitPrice: '1', flatFee: '0'), new Tier(index: 1, lower: '150', upper: null, unitPrice: '0.8', flatFee: '0')]));
        foreach (['0' => '0', '100' => '100', '149.999' => '149.999', '150' => '120', '200' => '160'] as $q => $expected) {
            $this->assertAmount($expected, $this->priced($volume, (string) $q));
        }
        $flat = $this->price(StrategyId::TieredGraduated, new GraduatedTerms(tiers: [$tiers[0], new Tier(index: 1, lower: '100', upper: null, unitPrice: '0.8', flatFee: '10')]));
        $this->assertAmount('100', $this->priced($flat, '100'));
        $this->assertAmount('150', $this->priced($flat, '150'));
    }

    public function test_pricing_strategy_commitment_consumes_frozen_basis(): void
    {
        $c = new CommitmentTerms(sellerRef: 'seller-a', billingAccountRef: 'customer', componentRef: 'api', currency: 'USD', periodRef: 'p1', minimumMinor: 20000, maximumMinor: 50000, consumedByAlreadyBilled: true, appliesAt: 'period_final');
        $price = $this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'), $c);
        foreach ([['140', '0', '200'], ['600', '0', '500'], ['40', '15000', '50'], ['140', '15000', '140'], ['10', '60000', '0']] as [$raw, $already, $expected]) {
            $this->assertAmount($expected, $this->priced($price, $raw, new CommitmentBasis(alreadyBilledNetMinor: $already, basisDigest: 'frozen')));
        }
        $this->expectExceptionMessage('billium.rating.terms_invalid');
        $this->priced($price, '140');
    }

    public function test_pricing_strategy_rejects_gaps_and_untrusted_digest(): void
    {
        $invalid = $this->price(StrategyId::TieredVolume, new VolumeTerms(tiers: [new Tier(index: 0, lower: '0', upper: '100', unitPrice: '1', flatFee: '0'), new Tier(index: 1, lower: '101', upper: null, unitPrice: '1', flatFee: '0')]));
        try {
            (new StrategyCatalog)->validate($invalid);
            self::fail('Gap was accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('billium.rating.terms_invalid', $exception->detail);
        }
        $version = $this->price(StrategyId::Fixed, new FixedTerms(amount: '1', scope: 'per_component_occurrence'));
        $tampered = new PriceVersion(sellerRef: $version->sellerRef, priceRef: $version->priceRef, version: 1, strategyId: $version->strategyId, strategyVersion: 1, currency: $version->currency, currencyExponent: 2, exponentVersion: $version->exponentVersion, unit: $version->unit, quantityRules: $version->quantityRules, terms: new FixedTerms(amount: '2', scope: 'per_component_occurrence'), roundingMode: $version->roundingMode, roundingStage: $version->roundingStage, roundingPolicyVersion: $version->roundingPolicyVersion, commitment: null, termsDigest: $version->termsDigest);
        $this->expectExceptionMessage('terms digest mismatch');
        (new StrategyCatalog)->validate($tampered);
    }

    public function test_pricing_strategy_fixture_examples_execute(): void
    {
        $path = dirname(__DIR__, 6).'/fixtures/vectors/rating/strategies/tier-boundaries.json';
        $example = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR)['given']['example'];
        $tiers = [];
        foreach ($example['tiers'] as $index => $tier) {
            $tiers[] = new Tier(index: $index, lower: $tier['lower'], upper: $tier['upper'], unitPrice: $tier['price'], flatFee: $tier['flat']);
        }
        foreach ([['graduated', StrategyId::TieredGraduated, new GraduatedTerms($tiers)], ['volume', StrategyId::TieredVolume, new VolumeTerms($tiers)]] as [$key, $id, $terms]) {
            $price = $this->price($id, $terms);
            foreach ($example[$key] as $case) {
                $this->assertAmount($case['raw'], $this->priced($price, $case['quantity']));
            }
        }
    }

    public function test_pricing_strategy_refuses_negative_quantity_inexact_conversion_and_invalid_bounds(): void
    {
        $price = $this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'));
        try {
            $this->priced($price, '-1');
            self::fail('negative usage accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('billium.rating.quantity_negative', $exception->detail);
        }
        $inexact = $this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'), rules: new QuantityRules(quantityScale: 1, conversionNumerator: '1', conversionDenominator: '3', billingIncrement: '1', incrementMode: IncrementMode::None));
        try {
            $this->priced($inexact, '1');
            self::fail('inexact conversion accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('billium.rating.terms_invalid', $exception->detail);
        }
        $invalid = new CommitmentTerms(sellerRef: 'seller-a', billingAccountRef: 'customer', componentRef: 'api', currency: 'USD', periodRef: 'p1', minimumMinor: 60000, maximumMinor: 50000, consumedByAlreadyBilled: true, appliesAt: 'period_final');
        $this->expectExceptionMessage('billium.rating.terms_invalid');
        (new StrategyCatalog)->validate($this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'), $invalid));
    }

    public function test_pricing_strategy_refuses_cross_component_scope_and_unknown_version(): void
    {
        $invalid = new CommitmentTerms(sellerRef: 'seller-a', billingAccountRef: 'customer', componentRef: '', currency: 'USD', periodRef: 'p1', minimumMinor: 1, maximumMinor: null, consumedByAlreadyBilled: true, appliesAt: 'period_final');
        try {
            (new StrategyCatalog)->validate($this->price(StrategyId::PerUnit, new PerUnitTerms(unitPrice: '1'), $invalid));
            self::fail('cross-component commitment accepted');
        } catch (ProblemException $exception) {
            self::assertStringContainsString('billium.rating.unsupported_combination', $exception->detail);
        }
        $this->expectExceptionMessage('billium.rating.strategy_unknown');
        (new StrategyCatalog)->resolve(id: StrategyId::Fixed, version: 2);
    }
}
