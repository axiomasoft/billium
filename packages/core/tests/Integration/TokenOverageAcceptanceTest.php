<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\PriceVersion as WirePriceVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\ContractCapabilities;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Enums\ReleasePolicy;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Adapters\WirePriceVersionMapper;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Storage\DatabasePriceVersionStore;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Testkit\Fixtures\FakeTarifflerOverageSource;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class TokenOverageAcceptanceTest extends RatingTestCase
{
    public function test_token_overage_end_to_end_60_50_minus_10_kopeks(): void
    {
        $caps = $this->application()->make(ContractCapabilities::class);
        self::assertTrue($caps->supportsTokenOverage());
        self::assertTrue($caps->supportsSpecRevision('2026-12'));
        self::assertTrue($caps->supportsUnit('token'));
        self::assertTrue($caps->supportsRatingStrategy('per_unit', 2));
        self::assertTrue($caps->hasRealUsageAttribution());

        $legacyCodec = Codec::forRevision('2026-10');
        $newCodec = Codec::forRevision('2026-12');

        // 1. Register token MetricVersion + SourceFinalityContract(AcceptAdjust, reopenAllowed=true)
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricRef = 'ai_tokens';
            $raw->metricVersion = 'ai_tokens/1';
            $raw->unit = 'token';
            $raw->sourceSelection = (object) [
                'sources' => ['tariffler'],
                'filters' => [(object) ['field' => 'billable', 'operator' => 'equals', 'value' => 'true']],
            ];
            $raw->billableStream = (object) [
                'mode' => 'normalized_net_billable',
                'policy' => 'exclusive_commercial_basis/1',
                'commercialBasisRef' => 'basis:ai:2026-10',
                'normalizationRef' => 'pool-1000/1',
            ];
        });
        $this->usage()->registerMetric($metric);

        try {
            $legacyCodec->decode($newCodec->encode($metric));
            self::fail('2026-10 codec must reject token metric');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        // 2. Wire rating.price.v1 -> WirePriceVersionMapper -> DatabasePriceVersionStore::publishWithWireEvidence
        $priceRaw = [
            'schema' => 'rating.price.v1',
            'sellerRef' => 'seller-a',
            'priceVersionRef' => 'price:ai-tokens:2026-10/v1',
            'componentRef' => 'component-1',
            'effectiveInterval' => ['start' => '2026-10-01T00:00:00Z', 'end' => '2026-11-01T00:00:00Z'],
            'unit' => 'token',
            'quantityPrecision' => 'decimal_24_9/1',
            'amountPrecision' => 'decimal_24_12/1',
            'currency' => 'RUB',
            'currencyExponentVersion' => 'ISO4217-2026-10',
            'strategy' => [
                'id' => 'per_unit',
                'version' => 2,
                'unitPrice' => '2.00',
                'quantityConversion' => [
                    'numerator' => '1',
                    'denominator' => '1000',
                    'quantityScale' => 9,
                ],
            ],
            'commitment' => [
                'policy' => 'same_component_period/1',
                'scope' => 'seller_customer_component_period',
                'minimumMinor' => '0',
                'maximumMinor' => '9223372036854775807',
            ],
            'modifierOrder' => 'strategy_bounds_tax_round/1',
            'rounding' => [
                'policy' => 'named_boundaries/1',
                'mode' => 'HALF_UP',
                'boundaries' => 'subtotal_then_bounds_then_tax',
            ],
            'taxSnapshot' => [
                'snapshotRef' => 'host-tax-1',
                'snapshotDigest' => 'sha256:'.str_repeat('0', 64),
                'policyVersion' => 'host-approved-tax/1',
                'correctionPolicyVersion' => 'host-approved-correction/1',
                'mode' => 'exclusive',
                'rate' => '0',
            ],
            'chargeTiming' => 'arrears',
            'quantityBasis' => 'aggregate',
            'billableStream' => [
                'mode' => 'normalized_net_billable',
                'policy' => 'exclusive_commercial_basis/1',
                'commercialBasisRef' => 'basis:ai:2026-10',
                'normalizationRef' => 'pool-1000/1',
            ],
            'allowancePolicy' => 'none/1',
            'commercialPolicyRef' => 'approved-agreement-1',
        ];
        $priceRaw['digest'] = $newCodec->digest($priceRaw);
        $priceJson = $newCodec->canonicalize($priceRaw);

        try {
            $legacyCodec->decode($priceJson);
            self::fail('2026-10 codec must reject per_unit@2 token price');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        $wirePrice = $newCodec->decode($priceJson);
        self::assertInstanceOf(WirePriceVersion::class, $wirePrice);
        $mapped = (new WirePriceVersionMapper)->map($wirePrice, 'price-ai-tokens', 1);
        $store = $this->application()->make(DatabasePriceVersionStore::class);
        $store->publishWithWireEvidence($mapped['price'], 'publish-token-price-1', $mapped['wireDigest'], $mapped['billableStream']);

        // Conflict checks on wire price publication:
        $changedStream = $mapped['billableStream'];
        $changedStream['normalizationRef'] = 'pool-2000/1';
        try {
            $store->publishWithWireEvidence($mapped['price'], 'publish-token-price-1', $mapped['wireDigest'], $changedStream);
            self::fail('Expected IdempotencyConflict for same commandKey with different billableStream');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        try {
            $store->publishWithWireEvidence($mapped['price'], 'publish-token-price-other-key', $mapped['wireDigest'], $changedStream);
            self::fail('Expected PolicyUnknown for new commandKey with different billableStream');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::PolicyUnknown, $e->problem);
        }

        // 3. Configure policy + completeness + FakeTarifflerOverageSource
        $attr = UsageFixtures::fact()->attribution;
        /** @var array<string, mixed> $attrArray */
        $attrArray = json_decode($newCodec->canonicalize($attr), true, 16, JSON_THROW_ON_ERROR);
        $source = new FakeTarifflerOverageSource(
            codec: $newCodec,
            sellerRef: 'seller-a',
            sourceRef: 'tariffler',
            metricRef: 'ai_tokens',
            metricVersion: 'ai_tokens/1',
            metricDigest: $metric->digest,
            commercialBasisRef: 'basis:ai:2026-10',
            normalizationRef: 'pool-1000/1',
            periodRef: 'october-2026',
            includedPoolTokens: 1000,
            originalAttribution: $attrArray,
            periodStartIso: '2026-10-01T00:00:00Z',
            periodEndIso: '2026-11-01T00:00:00Z',
        );

        self::assertNull($source->recordPhysicalTokens('evt-1', 800, '2026-10-10T10:00:00Z', '2026-10-10T10:00:01Z'));
        $overage300 = $source->recordPhysicalTokens('evt-2', 500, '2026-10-15T12:00:00Z', '2026-10-15T12:00:02Z');
        self::assertNotNull($overage300);
        self::assertSame($overage300, $source->recordPhysicalTokens('evt-2', 500, '2026-10-15T12:00:00Z', '2026-10-15T12:00:09Z'));

        $fact1 = $newCodec->decode($newCodec->canonicalize($overage300));
        self::assertInstanceOf(UsageFactV2::class, $fact1);

        // 10 identical deliveries -> 1 row, 0 obligations at intake
        $beforeObs = $this->rows('billium_obligation');
        $firstReceipt = null;
        for ($i = 0; $i < 10; $i++) {
            $r = $this->usage()->submitUsageFact($fact1);
            $firstReceipt ??= $r;
            self::assertSame($firstReceipt->receiptRef, $r->receiptRef);
        }
        self::assertSame(1, $this->rows('billium_usage_fact', "fact_id = 'overage:basis:ai:2026-10:october-2026'"));
        self::assertSame($beforeObs, $this->rows('billium_obligation'));

        // Conflict on modified quantity with same rev=1
        $conflicting = $overage300;
        self::assertIsArray($conflicting['value']);
        $conflicting['value']['quantity'] = '301';
        $conflicting['digest'] = $newCodec->digest($conflicting);
        $conflictingFact = $newCodec->decode($newCodec->canonicalize($conflicting));
        self::assertInstanceOf(UsageFactV2::class, $conflictingFact);
        try {
            $this->usage()->submitUsageFact($conflictingFact);
            self::fail('Expected IdempotencyConflict');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }

        // 4. Policy, completeness, freeze, prepare & book -> 60 kopeks
        $this->owner()->table('billium_usage_aggregation_policy')->insert([
            'seller_ref' => 'seller-a',
            'environment' => 'test',
            'metric_ref' => 'ai_tokens',
            'metric_version' => 'ai_tokens/1',
            'metric_definition_digest' => $metric->digest,
            'semantics' => 'additive',
            'algorithm_id' => 'additive_sum',
            'algorithm_version' => 1,
            'gauge_policy' => null,
            'missing_initial' => 'refuse',
            'carry_forward' => false,
            'quantity_scale' => 9,
            'rounding_mode' => 'half_up',
            'allow_negative' => false,
            'finality_contract_ref' => 'source-finality',
            'finality_contract_version' => 1,
            'identity_key_version' => null,
            'identity_namespace' => null,
            'cross_source_identity_policy' => 'shared',
        ]);
        $startMicros = (int) (new DateTimeImmutable('2026-10-01T00:00:00Z'))->format('U') * 1_000_000;
        $endMicros = (int) (new DateTimeImmutable('2026-11-01T00:00:00Z'))->format('U') * 1_000_000;
        $this->completeness(status: 'complete', sequence: 10);

        $groupKey = $this->window()->groupKey;
        $window = new WindowSelector('seller-a', 'test', 'ai_tokens', 'ai_tokens/1', $groupKey, $startMicros, $endMicros);
        $freeze1 = $this->application()->make(AggregationService::class)->freeze(new FreezeAggregate($window, commandKey: 'freeze-token-1'));
        $snap1Ref = FixtureValues::text($freeze1->body()['snapshotRef']);
        $snap1 = $this->application()->make(AggregationQueries::class)->snapshot($snap1Ref);
        self::assertSame('token', $snap1['unit']);

        $occ = $this->occurrenceRef;
        $this->tax->rate = '0';
        $prep1 = $this->application()->make(RatingPreparer::class)->prepare(new PrepareRating(
            occurrenceRef: $occ,
            billingAccountRef: 'original-account',
            attribution: CanonicalJson::encode($attrArray),
            agreementRevision: '1',
            occurrenceInputsDigest: self::inputDigest(),
            sourceDigest: 'source:token:1',
            components: [['componentRef' => 'component-1', 'snapshotRef' => $snap1Ref, 'priceRef' => 'price-ai-tokens', 'priceVersion' => 1]],
        ));
        self::assertInstanceOf(PreparedRating::class, $prep1);
        $booked = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis($prep1, 'book-token-1'));
        self::assertSame(BookingOutcome::Booked, $booked->outcome);
        self::assertSame('60', $booked->grossMinor);
        self::assertSame('RUB', $booked->currency);
        self::assertSame(1, $this->rows('billium_obligation'));

        // Issue invoice on 60 kopeks
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account');
        $ledger = $this->application()->make(Ledger::class);
        self::assertTrue($ledger->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-token-1',
            scope: $scope,
            invoiceRef: 'inv-token-1',
            customerRef: 'original-customer',
            lines: [new InvoiceLineInput(obligationRef: (string) $booked->obligationRef)],
        ))->accepted);
        self::assertTrue($ledger->issueInvoice(new IssueInvoice(
            commandKey: 'issue-inv-token-1',
            scope: $scope,
            invoiceRef: 'inv-token-1',
        ))->accepted);

        // 5. Disable live mapping -> replay rev=1 still works!
        $this->application()->instance(UsageAttribution::class, new class implements UsageAttribution
        {
            public function resolve(Caller $caller, UsageFactV2 $fact): ?AttributionSnapshot
            {
                return null;
            }
        });
        $replayed = $this->usage()->submitUsageFact($fact1);
        self::assertNotNull($firstReceipt);
        self::assertSame($firstReceipt->receiptRef, $replayed->receiptRef);

        // 6. Correction 500 -> 450 (250 net) -> 50 kopeks -> -10 kopeks credit note
        $this->application()->instance(UsageAttribution::class, new UsageFixtures);
        $overage250 = $source->recordPhysicalTokens('evt-2', 450, '2026-10-15T12:00:00Z', '2026-11-05T09:00:00Z', 'corr-evt-2-450');
        self::assertNotNull($overage250);
        $fact2 = $newCodec->decode($newCodec->canonicalize($overage250));
        self::assertInstanceOf(UsageFactV2::class, $fact2);
        $this->usage()->submitUsageFact($fact2);

        $freeze2 = $this->application()->make(AggregationService::class)->freeze(new FreezeAggregate(
            window: $window,
            expectedHeadDigest: FixtureValues::text($snap1['snapshotDigest']),
            commandKey: 'freeze-token-2',
        ));
        $snap2Ref = FixtureValues::text($freeze2->body()['snapshotRef']);
        self::assertNotSame($snap1Ref, $snap2Ref);

        $corrDate = new DateTimeImmutable('2026-11-05T09:00:00Z');
        $this->publishZeroTaxCorrectionPolicy($corrDate);
        $corr = $this->application()->make(RatingCorrection::class);
        $prepCorr = $corr->prepare(new PrepareCorrection(
            sellerRef: 'seller-a',
            environment: 'test',
            parentOccurrenceRef: $occ,
            kind: CorrectionKind::LateUsage,
            amendment: null,
            reasonCode: 'token_overage_adjustment',
            releasePolicy: ReleasePolicy::OpenOnly,
            correctionEffectiveDate: $corrDate,
        ));
        self::assertInstanceOf(PreparedCorrection::class, $prepCorr, json_encode($prepCorr, JSON_THROW_ON_ERROR));
        $corrReceipt = $corr->apply(new ApplyCorrection($prepCorr, 'apply-token-corr-1'));
        self::assertSame('credit_note', $corrReceipt->outcome);
        self::assertSame('-10', $corrReceipt->deltaGrossMinor);
        self::assertNotNull($corrReceipt->creditNoteRef);
        self::assertSame(1, $this->rows('billium_credit_note'));
    }

    public function test_disabled_usage_holds_metered_rating_while_fixed_price_continues_and_exclusive_basis_rejects_duplicates(): void
    {
        // RatingTestCase::setUp() already publishes metered price-1 v1; use a non-existent snapshotRef
        // RFC Criterion 5: Metered rating without an active usage snapshot holds with IncompleteSource
        $meteredPrice = $this->price(version: 1);
        $held = $this->application()->make(RatingPreparer::class)->prepare(new PrepareRating(
            occurrenceRef: $this->occurrenceRef,
            billingAccountRef: 'original-account',
            attribution: CanonicalJson::encode([
                'accountRef' => 'original-account',
                'agreementRef' => 'agr-1',
                'customerRef' => 'original-customer',
                'productRef' => 'prod-ai',
                'serviceRef' => 'svc-llm',
            ]),
            agreementRevision: '1',
            occurrenceInputsDigest: self::inputDigest(),
            sourceDigest: 'source:missing:1',
            components: [['componentRef' => 'component-1', 'snapshotRef' => 'non-existent-snapshot', 'priceRef' => $meteredPrice->priceRef, 'priceVersion' => 1]],
        ));
        self::assertInstanceOf(RatingHold::class, $held);
        self::assertSame(HoldReason::IncompleteSource, $held->reason);
        self::assertSame(0, $this->rows('billium_obligation'));

        // Fixed-price component continues working independently of usage
        $fixedPrice = $this->price(version: 2, fixed: '500');
        $this->application()->make(DatabasePriceVersionStore::class)->publish($fixedPrice, 'pub-fixed-plan-2');

        $fixedPrep = $this->prepared(priceVersion: 2);
        $fixedBook = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis($fixedPrep, 'book-fixed-1'));
        self::assertSame(BookingOutcome::Booked, $fixedBook->outcome);
        self::assertSame('50000', $fixedBook->grossMinor);
        self::assertSame(1, $this->rows('billium_obligation'));

        // exclusive_commercial_basis/1 prevents duplicate obligations on the same occurrence
        $dupBook = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis($fixedPrep, 'book-fixed-dup'));
        self::assertSame(BookingOutcome::Existing, $dupBook->outcome);
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    private function publishZeroTaxCorrectionPolicy(DateTimeImmutable $date): void
    {
        $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $makePolicy = static function (string $inputDigest, DateTimeImmutable $taxPoint) use ($from): TaxCorrectionPolicySnapshot {
            $rate = new TaxSnapshot(
                snapshotRef: 'correction-tax:zero',
                kind: TaxSnapshotKind::Correction,
                mode: TaxMode::NoneDeclared,
                jurisdiction: 'TEST',
                taxCode: 'test-tax',
                rate: '0',
                effectiveDate: $from,
                taxPoint: $taxPoint,
                noTaxReason: 'test-exempt',
                resolver: 'host',
                resolverVersion: '1',
                inputDigest: $inputDigest,
                evidenceDigest: 'rate-evidence',
            );

            return new TaxCorrectionPolicySnapshot(
                policyRef: 'policy',
                policyVersion: '2',
                treatment: 'current_legal_rate',
                selectedRate: $rate,
                effectiveFrom: $from,
                effectiveTo: null,
                inputDigest: $inputDigest,
                outputDigest: 'output',
                resolverVersion: '1',
            );
        };
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish($makePolicy('input', $date), 'policy-token-zero');
        $this->application()->instance(TaxSnapshotPort::class, new readonly class($makePolicy) implements TaxSnapshotPort
        {
            /** @param \Closure(string, DateTimeImmutable): TaxCorrectionPolicySnapshot $makePolicy */
            public function __construct(private \Closure $makePolicy) {}

            public function resolve(TaxResolutionRequest $request): TaxSnapshot
            {
                throw new \LogicException('Only correction resolution expected');
            }

            public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
            {
                return ($this->makePolicy)($request->inputDigest, $request->originalTaxPoint);
            }
        });
    }
}
