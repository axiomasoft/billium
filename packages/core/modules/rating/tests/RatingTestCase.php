<?php

declare(strict_types=1);

namespace Billium\Rating\Tests;

use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Laravel\RatingServiceProvider;
use Billium\Rating\Tests\Support\TestTaxPort;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Schedule\Tests\Support\Terms;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

abstract class RatingTestCase extends AggregationTestCase
{
    protected TestTaxPort $tax;

    protected string $occurrenceRef;

    protected string $snapshotRef;

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RatingServiceProvider::class, ScheduleServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'usage', 'rating', 'schedule']);
        $app->make(Repository::class)->set('billium.rating.enabled', true);
        $app->make(Repository::class)->set('billium.schedule.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
        $this->tax = new TestTaxPort;
        $this->application()->instance(TaxSnapshotPort::class, $this->tax);
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->value->quantity = '100';
        }));
        $this->snapshotRef = FixtureValues::text($this->freeze()['snapshotRef']);
        $service = $this->application()->make(OccurrenceService::class);
        $service->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRevision: 0, expectedRowVersion: 0, newRevision: 1, terms: Terms::make(['componentRef' => 'component-1', 'anchorLocal' => '2026-10-01T00:00:00', 'purpose' => 'usage_settlement']), effectiveFrom: new DateTimeImmutable('2026-01-01Z'), inputsDigest: self::inputDigest(), commandKey: 'schedule-revision'));
        $this->occurrenceRef = $this->occurrence(OccurrencePurpose::UsageSettlement);
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(), commandKey: 'price-v1');
    }

    protected static function inputDigest(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }

    protected function occurrence(OccurrencePurpose $purpose): string
    {
        $receipt = $this->application()->make(OccurrenceService::class)->ensureOccurrence(new EnsureOccurrence(sellerRef: 'seller-a', environment: 'test', componentRef: 'component-1', purpose: $purpose, periodStart: new DateTimeImmutable('2026-10-01Z'), periodEnd: new DateTimeImmutable('2026-11-01Z'), scheduleRef: 'schedule-a', scheduleRevision: 1, inputsDigest: self::inputDigest(), commandKey: 'ensure-'.$purpose->value));

        return FixtureValues::text($receipt->body()['occurrenceRef']);
    }

    protected function price(int $version = 1, ?string $fixed = null, bool $commitment = false): PriceVersion
    {
        $args = ['sellerRef' => 'seller-a', 'priceRef' => 'api', 'version' => $version, 'strategyId' => $fixed === null ? StrategyId::TieredGraduated : StrategyId::Fixed, 'strategyVersion' => 1, 'currency' => 'USD', 'currencyExponent' => 2, 'exponentVersion' => 'ISO4217-2026-10', 'unit' => 'api-call', 'quantityRules' => new QuantityRules(quantityScale: 9, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None), 'terms' => $fixed === null ? new GraduatedTerms(tiers: [new Tier(index: 0, lower: '0', upper: '100', unitPrice: '1', flatFee: '0'), new Tier(index: 1, lower: '100', upper: null, unitPrice: '0.8', flatFee: '0')]) : new FixedTerms(amount: $fixed, scope: 'per_component_occurrence'), 'roundingMode' => RoundingMode::HalfUp, 'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'test-rounding/1', 'commitment' => $commitment ? new CommitmentTerms(sellerRef: 'seller-a', billingAccountRef: 'original-account', componentRef: 'component-1', currency: 'USD', periodRef: 'october-2026', minimumMinor: 20000, maximumMinor: 50000, consumedByAlreadyBilled: true, appliesAt: 'period_final') : null];
        $draft = FixtureArguments::construct(class: PriceVersion::class, arguments: [...$args, 'termsDigest' => 'draft']);

        return FixtureArguments::construct(class: PriceVersion::class, arguments: [...$args, 'termsDigest' => CanonicalJson::termsDigest($draft)]);
    }

    protected function request(int $priceVersion = 1, ?string $ref = null, ?string $noCharge = null): PrepareRating
    {
        return new PrepareRating(occurrenceRef: $ref ?? $this->occurrenceRef, billingAccountRef: 'original-account', attribution: CanonicalJson::encode(get_object_vars(UsageFixtures::fact()->attribution)), agreementRevision: '1', occurrenceInputsDigest: self::inputDigest(), sourceDigest: 'source:test:1', components: [['componentRef' => 'component-1', 'snapshotRef' => $this->snapshotRef, 'priceRef' => 'api', 'priceVersion' => $priceVersion]], noChargeReason: $noCharge);
    }

    protected function prepared(int $priceVersion = 1, ?string $ref = null, ?string $noCharge = null): PreparedRating
    {
        $result = $this->application()->make(RatingPreparer::class)->prepare($this->request(priceVersion: $priceVersion, ref: $ref, noCharge: $noCharge));
        self::assertInstanceOf(PreparedRating::class, $result, json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    protected function book(PreparedRating $prepared, string $key = 'book-1'): BookingReceipt
    {
        return $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: $key));
    }

    /** @return array<string, int> */
    protected function ratingCounts(): array
    {
        $counts = [];
        foreach (['run', 'rated_basis', 'rated_line', 'booking', 'source_frontier', 'event'] as $table) {
            $counts[$table] = $this->rows('billium_rating_'.$table);
        }
        $counts['obligation'] = $this->rows('billium_obligation');

        return $counts;
    }
}
