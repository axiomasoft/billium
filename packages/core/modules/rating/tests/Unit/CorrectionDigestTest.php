<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Corrections\CorrectionDigest;
use Billium\Rating\Internal\Corrections\DeltaCalculator;
use Billium\Rating\Internal\Corrections\TaxCorrectionGroup;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CorrectionDigestTest extends TestCase
{
    private function policy(string $version): TaxCorrectionPolicySnapshot
    {
        $instant = new DateTimeImmutable('2027-01-01Z');
        $rate = new TaxSnapshot(snapshotRef: 'tax:'.$version, kind: TaxSnapshotKind::Correction, mode: TaxMode::Exclusive, jurisdiction: 'example', taxCode: 'test', rate: '0.20', effectiveDate: $instant, taxPoint: $instant, noTaxReason: null, resolver: 'host', resolverVersion: '1', inputDigest: 'input', evidenceDigest: 'evidence:'.$version);

        return new TaxCorrectionPolicySnapshot(policyRef: 'policy', policyVersion: $version, treatment: 'original_rate', selectedRate: $rate, effectiveFrom: $instant, effectiveTo: null, inputDigest: 'input', outputDigest: 'output:'.$version, resolverVersion: '1');
    }

    public function test_frontier_is_order_independent_but_tracks_tax_policy_changes(): void
    {
        $one = $this->policy('1');
        $two = $this->policy('2');
        $first = CorrectionDigest::frontier(sourceSnapshotDigests: ['b', 'a'], policy: $one);
        self::assertSame($first, CorrectionDigest::frontier(sourceSnapshotDigests: ['a', 'b'], policy: $one));
        self::assertNotSame($first, CorrectionDigest::frontier(sourceSnapshotDigests: ['a', 'b'], policy: $two));
        self::assertNotSame($first, CorrectionDigest::frontier(sourceSnapshotDigests: ['a', 'c'], policy: $one));
    }

    public function test_original_period_vectors_compare_full_amounts(): void
    {
        $path = dirname(__DIR__, 6).'/fixtures/vectors/rating/corrections/original-period.json';
        /** @var array{given: array{example: array{late_usage: array{volume: array{original_quantity: string, original_net_minor: string, corrected_quantity: string, corrected_net_minor: string, delta_minor: string}, graduated: array{original_quantity: string, original_net_minor: string, corrected_quantity: string, corrected_net_minor: string, delta_minor: string}, negative_graduated: array{original_quantity: string, original_net_minor: string, corrected_quantity: string, corrected_net_minor: string, delta_minor: string}}, partial_cancel: array{period_start: string, period_end: string, effective_at: string, full_minor: string, corrected_minor: string, delta_minor: string}, tax: array{original_net_minor: string, original_tax_minor: string, corrected_net_minor: string, original_rate_tax_minor: string, current_rate_tax_minor: string}}}} $vector */
        $vector = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $fixture = $vector['given']['example'];
        foreach ($fixture['late_usage'] as $case) {
            $delta = DeltaCalculator::delta(lastEffective: ['net' => $case['original_net_minor'], 'tax' => '0', 'gross' => $case['original_net_minor']], corrected: ['net' => $case['corrected_net_minor'], 'tax' => '0', 'gross' => $case['corrected_net_minor']]);
            self::assertSame($case['delta_minor'], $delta['gross']);
        }
    }

    public function test_different_rates_or_modes_are_not_a_single_original_tax_group(): void
    {
        $first = $this->policy('1')->selectedRate;
        $second = FixtureArguments::construct(class: TaxSnapshot::class, arguments: [...get_object_vars($first), 'rate' => '0.10']);
        $inclusive = FixtureArguments::construct(class: TaxSnapshot::class, arguments: [...get_object_vars($first), 'mode' => TaxMode::Inclusive]);
        self::assertTrue(TaxCorrectionGroup::isHomogeneous([$first, $first]));
        self::assertFalse(TaxCorrectionGroup::isHomogeneous([$first, $second]));
        self::assertFalse(TaxCorrectionGroup::isHomogeneous([$first, $inclusive]));
    }
}
