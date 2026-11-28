<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenPrice;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Usage\Contracts\AggregationQueries;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class MeteredUnitIntegrityPgTest extends RatingTestCase
{
    private function publishPriceWithUnit(int $version, string $unit): PriceVersion
    {
        $args = [
            'sellerRef' => 'seller-a',
            'priceRef' => 'api-'.$unit,
            'version' => $version,
            'strategyId' => StrategyId::TieredGraduated,
            'strategyVersion' => 1,
            'currency' => 'USD',
            'currencyExponent' => 2,
            'exponentVersion' => 'ISO4217-2026-10',
            'unit' => $unit,
            'quantityRules' => new QuantityRules(
                quantityScale: 9,
                conversionNumerator: '1',
                conversionDenominator: '1',
                billingIncrement: '1',
                incrementMode: IncrementMode::None,
            ),
            'terms' => new GraduatedTerms(tiers: [
                new Tier(index: 0, lower: '0', upper: '100', unitPrice: '1', flatFee: '0'),
                new Tier(index: 1, lower: '100', upper: null, unitPrice: '0.8', flatFee: '0'),
            ]),
            'roundingMode' => RoundingMode::HalfUp,
            'roundingStage' => RoundingStage::AfterTax,
            'roundingPolicyVersion' => 'test-rounding/1',
            'commitment' => null,
        ];
        $draft = FixtureArguments::construct(class: PriceVersion::class, arguments: [...$args, 'termsDigest' => 'draft']);
        $price = FixtureArguments::construct(class: PriceVersion::class, arguments: [...$args, 'termsDigest' => CanonicalJson::termsDigest($draft)]);
        $this->application()->make(PriceVersionStore::class)->publish(price: $price, commandKey: 'price-'.$unit.'-v'.$version);

        return $price;
    }

    public function test_snapshot_view_exposes_metric_unit_after_freeze(): void
    {
        $snapshot = $this->application()->make(AggregationQueries::class)->snapshot($this->snapshotRef);
        self::assertArrayHasKey('unit', $snapshot);
        self::assertSame('call', $snapshot['unit']);
    }

    public function test_prepare_and_book_reject_mismatched_unit_without_creating_obligations(): void
    {
        $this->publishPriceWithUnit(1, 'byte');
        $before = $this->ratingCounts();
        $request = $this->request();
        $request = new PrepareRating(
            occurrenceRef: $request->occurrenceRef,
            billingAccountRef: $request->billingAccountRef,
            attribution: $request->attribution,
            agreementRevision: $request->agreementRevision,
            occurrenceInputsDigest: $request->occurrenceInputsDigest,
            sourceDigest: $request->sourceDigest,
            components: [['componentRef' => 'component-1', 'snapshotRef' => $this->snapshotRef, 'priceRef' => 'api-byte', 'priceVersion' => 1]],
        );

        $result = $this->application()->make(RatingPreparer::class)->prepare($request);
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertStringContainsString('billium.rating.unit_mismatch', $result->detail);

        self::assertSame($before, $this->ratingCounts());
    }

    public function test_booking_rejects_forged_unit_under_usage_lock(): void
    {
        $prepared = $this->prepared();
        $bytePrice = $this->publishPriceWithUnit(1, 'byte');
        $agg = $prepared->inputs->aggregates[0];
        $forgedSnapshot = $agg->snapshot;
        $forgedSnapshot['unit'] = 'byte';
        $forgedAgg = FixtureArguments::construct(class: FrozenAggregate::class, arguments: [
            ...get_object_vars($agg),
            'snapshot' => $forgedSnapshot,
            ...(property_exists($agg, 'unit') ? ['unit' => 'byte'] : []),
        ]);
        $oldFp = $prepared->inputs->prices[0];
        $forgedFp = new FrozenPrice(
            componentRef: $oldFp->componentRef,
            priceRef: $bytePrice->priceRef,
            priceVersion: $bytePrice->version,
            strategyId: $bytePrice->strategyId->value,
            strategyVersion: $bytePrice->strategyVersion,
            termsDigest: $bytePrice->termsDigest,
            commitmentScopeDigest: null,
            commitmentBasisDigest: null,
            alreadyBilledNetMinor: null,
            roundingPolicyVersion: $bytePrice->roundingPolicyVersion,
            exponentVersion: $bytePrice->exponentVersion,
            snapshot: $bytePrice,
        );
        $forgedInputs = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [
            ...get_object_vars($prepared->inputs),
            'aggregates' => [$forgedAgg],
            'prices' => [$forgedFp],
        ]);
        $forgedDraft = $this->application()->make(RatingComputation::class)->compute($forgedInputs);

        $before = $this->ratingCounts();
        try {
            $this->application()->make(RatingBooking::class)->book(
                new BookRatedBasis(prepared: new PreparedRating($forgedInputs, $forgedDraft), operationKey: 'forged-unit'),
            );
            self::fail('Expected booking to refuse forged unit under usage lock');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::UsageConflict, $e->problem);
            self::assertStringContainsString('snapshot_superseded', $e->detail);
        }

        self::assertSame($before, $this->ratingCounts());
    }

    public function test_matching_unit_books_successfully(): void
    {
        $prepared = $this->prepared();
        $receipt = $this->book($prepared);
        self::assertSame(BookingOutcome::Booked, $receipt->outcome);
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_booking_with_unit_only_in_nested_snapshot_verifies_under_usage_lock(): void
    {
        $prepared = $this->prepared();
        $agg = $prepared->inputs->aggregates[0];
        $nestedOnlySnapshot = $agg->snapshot;
        $nestedOnlySnapshot['unit'] = 'call';
        $nestedAgg = FixtureArguments::construct(class: FrozenAggregate::class, arguments: [
            ...get_object_vars($agg),
            'snapshot' => $nestedOnlySnapshot,
            'unit' => null,
        ]);
        $nestedInputs = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [
            ...get_object_vars($prepared->inputs),
            'aggregates' => [$nestedAgg],
        ]);
        $nestedDraft = $this->application()->make(RatingComputation::class)->compute($nestedInputs);
        $receipt = $this->application()->make(RatingBooking::class)->book(
            new BookRatedBasis(prepared: new PreparedRating($nestedInputs, $nestedDraft), operationKey: 'nested-unit-only'),
        );
        self::assertSame(BookingOutcome::Booked, $receipt->outcome);
    }

    public function test_historical_snapshot_without_metric_unit_recovers_unit_from_metric(): void
    {
        $owner = $this->owner();
        $row = $owner->table('billium_usage_aggregate_snapshot')->where('snapshot_ref', $this->snapshotRef)->first();
        self::assertNotNull($row);
        $legacyRef = 'agg_legacy_no_unit_1';
        $owner->table('billium_usage_aggregate_snapshot')->insert([
            'snapshot_ref' => $legacyRef,
            'snapshot_digest' => 'sha256:'.str_repeat('b', 64),
            'seller_ref' => $row->seller_ref,
            'environment' => $row->environment,
            'metric_ref' => $row->metric_ref,
            'metric_version' => $row->metric_version,
            'metric_unit' => null,
            'group_hash' => $row->group_hash,
            'group_key' => $row->group_key,
            'series_key' => $row->series_key.'-legacy',
            'window_start_micros' => $row->window_start_micros,
            'window_end_micros' => $row->window_end_micros,
            'snapshot_revision' => 1,
            'predecessor_digest' => null,
            'algorithm_id' => $row->algorithm_id,
            'algorithm_version' => $row->algorithm_version,
            'quantity' => $row->quantity,
            'quantity_scale' => $row->quantity_scale,
            'distinct_count' => $row->distinct_count,
            'member_set_digest' => $row->member_set_digest,
            'finality_contract_ref' => $row->finality_contract_ref,
            'finality_contract_version' => $row->finality_contract_version,
            'completeness_frontier' => $row->completeness_frontier,
            'completeness_frontier_digest' => $row->completeness_frontier_digest,
            'reduction_evidence' => $row->reduction_evidence,
            'ingest_frontier_seq' => $row->ingest_frontier_seq,
        ]);

        $view = $this->application()->make(AggregationQueries::class)->snapshot($legacyRef);
        self::assertSame('call', $view['unit']);
    }
}
