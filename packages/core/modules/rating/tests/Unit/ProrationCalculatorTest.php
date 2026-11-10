<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\CorrectionBasis as LedgerCorrectionBasis;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\ProrationPolicyId;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Corrections\DeltaCalculator;
use Billium\Rating\Internal\Math\Dec;
use Billium\Rating\Internal\Proration\AmendmentSupport;
use Billium\Rating\Internal\Proration\ProrationCalculator;
use Billium\Rating\Internal\Proration\ProrationPolicyCatalog;
use Billium\Schedule\Data\CorrectionBasis as ScheduleCorrectionBasis;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProrationCalculatorTest extends TestCase
{
    public function test_periods_use_exact_seconds_or_local_days(): void
    {
        $calc = new ProrationCalculator;
        foreach ([
            [ProrationPolicyId::ElapsedSeconds, '2027-01-01Z', '2027-02-01Z', '2027-01-16Z', null, '31.00', '1500'],
            [ProrationPolicyId::ElapsedSeconds, '2028-02-01Z', '2028-03-01Z', '2028-02-11Z', null, '29.00', '1000'],
            [ProrationPolicyId::ElapsedSeconds, '2027-03-01T05:00:00Z', '2027-04-01T04:00:00Z', '2027-03-15T04:00:00Z', 'America/New_York', '100.00', '4509'],
            [ProrationPolicyId::LocalCalendarDays, '2027-03-01T05:00:00Z', '2027-04-01T04:00:00Z', '2027-03-15T04:00:00Z', 'America/New_York', '100.00', '4516'],
            [ProrationPolicyId::None, '2027-01-01Z', '2027-02-01Z', '2027-01-16Z', null, '31.00', '3100'],
        ] as [$policy, $start, $end, $effective, $tz, $full, $expected]) {
            $fraction = $calc->fraction(policy: $policy, start: new DateTimeImmutable($start), end: new DateTimeImmutable($end), effectiveAt: new DateTimeImmutable($effective), timezone: $tz);
            self::assertSame($expected, $calc->prorateMinor(fullMajor: Dec::parse($full), exponent: 2, elapsed: $fraction['elapsed'], total: $fraction['total'], roundingMode: 'half_up'));
        }
    }

    public function test_local_day_rejects_non_midnight(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.rating.proration_boundary_misaligned');
        (new ProrationCalculator)->fraction(policy: ProrationPolicyId::LocalCalendarDays, start: new DateTimeImmutable('2027-03-01T05:00:00Z'), end: new DateTimeImmutable('2027-04-01T04:00:00Z'), effectiveAt: new DateTimeImmutable('2027-03-15T12:00:00Z'), timezone: 'America/New_York');
    }

    public function test_unknown_policy_is_refused(): void
    {
        $this->expectException(ProblemException::class);
        (new ProrationPolicyCatalog)->resolve('unregistered');
    }

    public function test_unsupported_amendment_combinations_are_refused(): void
    {
        $args = ['sellerRef' => 's', 'priceRef' => 'p', 'version' => 1, 'strategyId' => StrategyId::Fixed, 'strategyVersion' => 1,
            'currency' => 'USD', 'currencyExponent' => 2, 'exponentVersion' => 'v1', 'unit' => 'seat',
            'quantityRules' => new QuantityRules(quantityScale: 0, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None),
            'terms' => new FixedTerms(amount: '1', scope: 'per_component_occurrence'), 'roundingMode' => RoundingMode::HalfUp,
            'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'v1', 'commitment' => null, 'termsDigest' => 'frozen'];
        $catalog = new AmendmentSupport;
        $valid = new PriceVersion(...$args);
        $catalog->assertSupported(kind: 'partial_cancel', price: $valid);
        $commitment = new CommitmentTerms(sellerRef: 's', billingAccountRef: 'account', componentRef: 'component', currency: 'USD',
            periodRef: 'period', minimumMinor: 100, maximumMinor: null, consumedByAlreadyBilled: true, appliesAt: 'period_final');
        foreach ([['pause_resume', $valid], ['seat_midperiod', $valid], ['discount', $valid],
            ['partial_cancel', new PriceVersion(...[...$args, 'strategyId' => StrategyId::TieredVolume])],
            ['partial_cancel', new PriceVersion(...[...$args, 'commitment' => $commitment])]] as [$kind, $price]) {
            try {
                $catalog->assertSupported(kind: $kind, price: $price);
                self::fail('Unsupported amendment accepted');
            } catch (ProblemException $error) {
                self::assertStringContainsString('billium.rating.amendment_unsupported', $error->detail);
            }
        }
    }

    public function test_backdate_is_rejected_before_proration(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.rating.amendment_unsupported');
        (new ProrationCalculator)->fraction(policy: ProrationPolicyId::ElapsedSeconds, start: new DateTimeImmutable('2027-01-01Z'),
            end: new DateTimeImmutable('2027-02-01Z'), effectiveAt: new DateTimeImmutable('2026-12-31Z'), timezone: null);
    }

    public function test_schedule_and_ledger_correction_basis_match(): void
    {
        self::assertSame(
            LedgerCorrectionBasis::derive(originalBasisRef: 'rating:original', correctionRef: 'occurrence', revision: 2),
            ScheduleCorrectionBasis::deriveLedger(originalBasisRef: 'rating:original', parentOccurrenceRef: 'occurrence', correctionRevision: 2),
        );
        self::assertNotSame(
            ScheduleCorrectionBasis::derive(parentOccurrenceRef: 'occurrence', correctionRevision: 2),
            ScheduleCorrectionBasis::deriveLedger(originalBasisRef: 'rating:original', parentOccurrenceRef: 'occurrence', correctionRevision: 2),
        );
    }

    public function test_delta_compares_full_bases_and_rejects_mixed_sign(): void
    {
        self::assertSame(['net' => '2000', 'tax' => '1000', 'gross' => '3000', 'branch' => 'obligation'], DeltaCalculator::delta(lastEffective: ['net' => '10000', 'tax' => '2000', 'gross' => '12000'], corrected: ['net' => '12000', 'tax' => '3000', 'gross' => '15000']));
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.rating.correction_mixed_sign');
        DeltaCalculator::delta(lastEffective: ['net' => '10000', 'tax' => '2000', 'gross' => '12000'], corrected: ['net' => '12000', 'tax' => '0', 'gross' => '12000']);
    }
}
