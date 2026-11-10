<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Allocate;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\InvoiceLineInput;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\HostAmendment;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\ProrationPolicyId;
use Billium\Rating\Enums\ReleasePolicy;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Storage\RatingStore;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Rating\Tests\Support\CorrectionScenario;
use Billium\Rating\Tests\Support\LedgerFault;
use Billium\Rating\Tests\Support\TestTaxPort;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\AttributionSnapshot;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class CorrectionIntegrationSmokeTest extends RatingTestCase
{
    /** @return array<string, array{string, string, string, string, string, string}> */
    public static function exactProrations(): array
    {
        return [
            'half' => ['2026-10-16T12:00:00Z', '2', '1', '3', '-3', '-3'],
            'non-terminating day fraction' => ['2026-10-16T00:00:00Z', '2', '1', '3', '-3', '-3'],
        ];
    }

    #[DataProvider('exactProrations')]
    public function test_partial_cancel_preserves_exact_prorated_fraction_through_after_tax_rounding(
        string $effectiveAt, string $net, string $tax, string $gross, string $deltaNet, string $deltaGross,
    ): void {
        $this->tax->rate = '0.25';
        $fields = [...get_object_vars($this->price(version: 2, fixed: '0.05')), 'roundingMode' => RoundingMode::HalfEven, 'termsDigest' => 'draft'];
        $fields['termsDigest'] = CanonicalJson::termsDigest(FixtureArguments::construct(class: PriceVersion::class, arguments: $fields));
        $this->application()->make(PriceVersionStore::class)->publish(
            price: FixtureArguments::construct(class: PriceVersion::class, arguments: $fields), commandKey: 'five-cent-price');
        $request = $this->request(priceVersion: 2);
        $components = $request->components;
        $components[0]['snapshotRef'] = 'fixed';
        $original = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(
            class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components]));
        self::assertInstanceOf(PreparedRating::class, $original);
        self::assertSame(['5', '1', '6'], [$original->draft->netMinor, $original->draft->taxMinor, $original->draft->grossMinor]);
        $booked = $this->book($original);
        $this->issueInvoice($booked->obligationRef);
        $port = $this->correctionTax(rate: '0.25', version: '1', treatment: 'original_rate');
        $date = new DateTimeImmutable($effectiveAt);
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef,
            kind: CorrectionKind::PartialCancel, amendment: new HostAmendment(amendmentRef: 'half-cancel', revision: 1, effectiveAt: $date,
                prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null),
            reasonCode: 'cancel', releasePolicy: ReleasePolicy::OpenOnly));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        $computed = $prepared->computation;
        self::assertSame([$net, $tax, $gross], [$computed->correctedBasis->netMinor, $computed->correctedBasis->taxMinor, $computed->correctedBasis->grossMinor]);
        self::assertSame([$deltaNet, '0', $deltaGross], [$computed->deltaNetMinor, $computed->deltaTaxMinor, $computed->deltaGrossMinor]);
        self::assertSame('0.02', $computed->correctedBasis->lines[0]->pricedAmount);
        self::assertSame('credit_note', $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'half-cancel'))->outcome);
        self::assertSame(3, $this->value('SELECT amount_minor FROM billium_credit_note'));
        self::assertSame('0.020000000000000000000000', $this->value('SELECT priced_amount::text FROM billium_rating_rated_line WHERE predecessor_basis_ref IS NOT NULL'));
    }

    public function test_tax_only_correction_creates_child_and_replays(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('500', $prepared->computation->deltaGrossMinor);
        $first = $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'correction-1'));
        self::assertSame('obligation', $first->outcome);
        self::assertSame('500', $first->deltaGrossMinor);
        $replay = $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'correction-2'));
        self::assertSame('existing', $replay->outcome);
        self::assertSame($first->obligationRef, $replay->obligationRef);
        self::assertSame(1, $this->rows('billium_rating_correction'));
        self::assertSame(2, $this->rows('billium_obligation'));
        $changed = new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'different', releasePolicy: null, correctionEffectiveDate: $date);
        $tampered = FixtureArguments::construct(class: PreparedCorrection::class, arguments: [...get_object_vars($prepared), 'command' => $changed]);
        try {
            $correction->apply(new ApplyCorrection(prepared: $tampered, operationKey: 'correction-1'));
            self::fail('Same operation key with changed payload was accepted');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }
    }

    public function test_late_usage_reprices_full_original_period(): void
    {
        $booked = $this->book($this->prepared());
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $invoice = $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='invoice-1'");
        $occurrence = $this->value('SELECT row_to_json(o)::text FROM billium_schedule_billing_occurrence o WHERE occurrence_ref=?', [$this->occurrenceRef]);
        $originalDigest = FixtureValues::text($this->queries()->snapshot($this->snapshotRef)['snapshotDigest']);
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'fact-2';
            $fact->value->quantity = '50';
        }));
        $this->freeze($originalDigest);
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('4000', $prepared->computation->deltaGrossMinor);
        self::assertSame('obligation', $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'late-1'))->outcome);
        self::assertSame(2, $this->rows('billium_obligation'));
        self::assertSame($invoice, $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='invoice-1'"));
        self::assertSame($occurrence, $this->value('SELECT row_to_json(o)::text FROM billium_schedule_billing_occurrence o WHERE occurrence_ref=?', [$this->occurrenceRef]));
    }

    /** @return list<array{string, string, string}> */
    public static function volumeTaxRates(): array
    {
        return [['0', '0', '2000'], ['0.20', '0.20', '2400'], ['0.25', '0.20', '3000']];
    }

    #[DataProvider('volumeTaxRates')]
    public function test_issued_volume_reprices_every_unit_at_original_tier(string $rate, string $originalRate, string $expected): void
    {
        $this->tax->rate = $originalRate;
        $base = $this->price();
        $fields = get_object_vars($base);
        $fields['strategyId'] = StrategyId::TieredVolume;
        $fields['terms'] = new VolumeTerms(tiers: [new Tier(index: 0, lower: '0', upper: '150', unitPrice: '1', flatFee: '0'), new Tier(index: 1, lower: '150', upper: null, unitPrice: '0.8', flatFee: '0')]);
        $fields['version'] = 2;
        $fields['termsDigest'] = 'draft';
        $draft = FixtureArguments::construct(class: PriceVersion::class, arguments: $fields);
        $fields['termsDigest'] = CanonicalJson::termsDigest($draft);
        $this->application()->make(PriceVersionStore::class)->publish(price: FixtureArguments::construct(class: PriceVersion::class, arguments: $fields), commandKey: 'volume-price');
        $booked = $this->book($this->prepared(priceVersion: 2));
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $invoice = $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='invoice-1'");
        $originalDigest = FixtureValues::text($this->queries()->snapshot($this->snapshotRef)['snapshotDigest']);
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'fact-2';
            $fact->value->quantity = '50';
        }));
        $this->freeze($originalDigest);
        $port = $this->correctionTax(rate: $rate, version: '1');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame($expected, $prepared->computation->deltaGrossMinor);
        self::assertSame('obligation', $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'volume-late'))->outcome);
        self::assertSame($invoice, $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='invoice-1'"));
        self::assertSame(1, $this->rows('billium_schedule_billing_correction'));
    }

    public function test_second_tax_only_policy_change_advances_same_usage_frontier(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $date = new DateTimeImmutable('2026-11-15Z');
        $first = $this->correctionTax(rate: '0.25', version: '2');
        $this->publishPolicy(port: $first, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $first);
        $command = new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date);
        $correction = $this->application()->make(RatingCorrection::class);
        $one = $correction->prepare($command);
        self::assertInstanceOf(PreparedCorrection::class, $one);
        $correction->apply(new ApplyCorrection(prepared: $one, operationKey: 'tax-1'));
        $second = $this->correctionTax(rate: '0.30', version: '3');
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish(policy: $second->resolveCorrection($one->taxRequest), commandKey: 'policy-v3');
        $this->application()->instance(TaxSnapshotPort::class, $second);
        $correction = $this->application()->make(RatingCorrection::class);
        $two = $correction->prepare($command);
        self::assertInstanceOf(PreparedCorrection::class, $two, json_encode($two, JSON_THROW_ON_ERROR));
        self::assertNotSame($one->computation->sourceFrontierDigest, $two->computation->sourceFrontierDigest);
        self::assertSame('500', $two->computation->deltaGrossMinor);
        $correction->apply(new ApplyCorrection(prepared: $two, operationKey: 'tax-2'));
        self::assertSame(2, $this->rows('billium_rating_correction'));
        self::assertSame(3, $this->rows('billium_obligation'));
    }

    public function test_commitment_scope_uses_latest_full_corrected_basis_once(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, commitment: true), commandKey: 'commitment-price');
        $original = $this->prepared(priceVersion: 2);
        self::assertSame('20000', $original->draft->netMinor);
        $this->book($original);
        $originalDigest = FixtureValues::text($this->queries()->snapshot($this->snapshotRef)['snapshotDigest']);
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'fact-2';
            $fact->value->quantity = '200';
        }));
        $this->freeze($originalDigest);
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('6000', $prepared->computation->deltaGrossMinor);
        $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'commitment-correction'));
        $scope = $original->inputs->prices[0]->commitmentScopeDigest;
        self::assertNotNull($scope);
        self::assertSame('26000', $this->application()->make(RatingStore::class)->commitment($scope)['alreadyBilledNetMinor']);
    }

    public function test_gauge_revision_replaces_the_sample_before_closed_period_rating(): void
    {
        $metric = UsageFixtures::metric(static function ($raw): void {
            $raw->metricVersion = 'gauge/1';
            $raw->model = (object) ['id' => 'gauge', 'version' => 1, 'revisionPolicy' => 'highest_fact_revision', 'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId'];
        });
        $this->usage()->registerMetric($metric);
        $this->owner()->table('billium_usage_aggregation_policy')->insert(['seller_ref' => 'seller-a', 'environment' => 'test', 'metric_ref' => $metric->metricRef, 'metric_version' => $metric->metricVersion, 'metric_definition_digest' => $metric->digest, 'semantics' => 'gauge', 'algorithm_id' => 'gauge_latest_as_of', 'algorithm_version' => 1, 'gauge_policy' => 'latest_as_of', 'missing_initial' => 'refuse', 'carry_forward' => false, 'quantity_scale' => 9, 'rounding_mode' => 'half_up', 'allow_negative' => false, 'finality_contract_ref' => 'source-finality', 'finality_contract_version' => 1, 'identity_key_version' => null, 'identity_namespace' => null, 'cross_source_identity_policy' => 'shared']);
        $fact = UsageFixtures::fact(static function ($raw) use ($metric): void {
            $raw->metricVersion = $metric->metricVersion;
            $raw->metricDigest = $metric->digest;
            $raw->factId = 'gauge-fact';
            $raw->value->quantity = '10';
        });
        $this->usage()->submitUsageFact($fact);
        $window = $this->window($metric);
        $snapshot = $this->aggregation()->freeze(new FreezeAggregate(window: $window))->body();
        $request = $this->request();
        $request = FixtureArguments::construct(class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => [['componentRef' => 'component-1', 'snapshotRef' => FixtureValues::text($snapshot['snapshotRef']), 'priceRef' => 'api', 'priceVersion' => 1]]]);
        $original = $this->application()->make(RatingPreparer::class)->prepare($request);
        self::assertInstanceOf(PreparedRating::class, $original);
        self::assertSame('1000', $original->draft->netMinor);
        $this->book($original);
        $this->usage()->submitUsageFact(UsageFixtures::correction(previous: $fact, quantity: '15'));
        $this->aggregation()->freeze(new FreezeAggregate(window: $window, expectedHeadDigest: FixtureValues::text($snapshot['snapshotDigest'])));
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'gauge-revision', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('500', $prepared->computation->deltaGrossMinor);
        self::assertSame('1500', $prepared->computation->correctedBasis->netMinor);
        $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'gauge-correction'));
        self::assertSame(2, $this->rows('billium_obligation'));
    }

    /** @return list<array{ReleasePolicy}> */
    public static function creditPolicies(): array
    {
        return [[ReleasePolicy::OpenOnly], [ReleasePolicy::ReverseAllocations]];
    }

    #[DataProvider('creditPolicies')]
    public function test_partial_cancel_credits_issued_invoice_and_keeps_original_amount(ReleasePolicy $release): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '31.00'), commandKey: 'fixed-price');
        $booked = $this->book($this->prepared(priceVersion: 2));
        self::assertNotNull($booked->obligationRef);
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account');
        $ledger = $this->application()->make(Ledger::class);
        self::assertTrue($ledger->createInvoice(new CreateInvoice(commandKey: 'draft', scope: $scope, invoiceRef: 'invoice-1', customerRef: 'original-customer', lines: [new InvoiceLineInput(obligationRef: $booked->obligationRef)]))->accepted);
        self::assertTrue($ledger->issueInvoice(new IssueInvoice(commandKey: 'issue', scope: $scope, invoiceRef: 'invoice-1'))->accepted);
        $invoiceBefore = $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='invoice-1'");
        if ($release === ReleasePolicy::ReverseAllocations) {
            $this->owner()->table('billium_payment')->insert(['installation_ref' => 'inst-test', 'seller_ref' => 'seller-a', 'environment' => 'test', 'billing_account_ref' => 'original-account', 'currency' => 'USD', 'payment_ref' => 'payment-1', 'source_authority' => 'authority', 'source_lineage' => 'lineage', 'operation_ref' => 'operation-1', 'kind' => 'charge', 'captured_minor' => 3100]);
            self::assertTrue($ledger->allocate(new Allocate(commandKey: 'allocate', scope: $scope, allocationRef: 'allocation-1', obligationRef: $booked->obligationRef, money: Money::parse('31.00', 'USD'), paymentRef: 'payment-1'))->accepted);
        }
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-10-16Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::PartialCancel, amendment: new HostAmendment(amendmentRef: 'cancel', revision: 1, effectiveAt: $date, prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null), reasonCode: 'cancel', releasePolicy: $release));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('-1600', $prepared->computation->deltaGrossMinor);
        self::assertSame('credit_note', $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'cancel-1'))->outcome);
        self::assertSame(1, $this->rows('billium_credit_note'));
        self::assertSame(3100, $this->value("SELECT total_minor FROM billium_invoice WHERE invoice_ref='invoice-1'"));
        self::assertNotSame($invoiceBefore, $this->value("SELECT row_to_json(i)::text FROM billium_invoice i WHERE invoice_ref='invoice-1'"));
        if ($release === ReleasePolicy::ReverseAllocations) {
            self::assertSame(1500, $this->value("SELECT allocated_minor FROM billium_payment WHERE payment_ref='payment-1'"));
            self::assertSame(1600, $this->value('SELECT credited_minor FROM billium_obligation WHERE obligation_ref=?', [$booked->obligationRef]));
            self::assertSame(0, $this->value('SELECT open_minor FROM billium_obligation WHERE obligation_ref=?', [$booked->obligationRef]));
        }
    }

    public function test_policy_publication_change_rejects_prepared_correction_atomically(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $firstPort = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $firstPort, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $firstPort);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        $other = $this->correctionTax(rate: '0.30', version: '3');
        $dummy = $prepared->taxRequest;
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish(policy: $other->resolveCorrection($dummy), commandKey: 'policy-v3');
        try {
            $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'stale'));
            self::fail('Stale tax policy was accepted');
        } catch (ProblemException $error) {
            self::assertStringContainsString('billium.rating.tax_policy_stale', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_amendment_revision_order_conflicts_and_competing_preparations(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '31.00'), commandKey: 'fixed-price');
        $booked = $this->book($this->prepared(priceVersion: 2));
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $port = $this->correctionTax(rate: '0', version: '1');
        $this->publishPolicy(port: $port, date: new DateTimeImmutable('2026-10-11Z'));
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $service = $this->application()->make(RatingCorrection::class);
        $occurrence = $this->occurrenceRef;
        $command = static fn (int $revision, string $date): PrepareCorrection => new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $occurrence, kind: CorrectionKind::PartialCancel, amendment: new HostAmendment(amendmentRef: 'cancel', revision: $revision, effectiveAt: new DateTimeImmutable($date), prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null), reasonCode: 'cancel', releasePolicy: ReleasePolicy::OpenOnly);
        $second = $service->prepare($command(2, '2026-10-11Z'));
        $competing = $service->prepare($command(3, '2026-10-16Z'));
        self::assertInstanceOf(PreparedCorrection::class, $second);
        self::assertInstanceOf(PreparedCorrection::class, $competing);
        $first = $service->apply(new ApplyCorrection(prepared: $second, operationKey: 'amendment-v2'));
        self::assertSame('existing', $service->apply(new ApplyCorrection(prepared: $second, operationKey: 'amendment-v2-replay'))->outcome);
        $head = $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'");
        foreach ([$command(1, '2026-10-16Z'), $command(2, '2026-10-16Z')] as $invalid) {
            try {
                $service->prepare($invalid);
                self::fail('Stale or conflicting amendment was accepted');
            } catch (ProblemException $error) {
                self::assertStringContainsString('amendment_', $error->detail);
            }
        }
        try {
            $service->apply(new ApplyCorrection(prepared: $competing, operationKey: 'amendment-v3-stale'));
            self::fail('A competing stale preparation was accepted');
        } catch (ProblemException $error) {
            self::assertStringContainsString('correction_frontier_stale', $error->detail);
        }
        self::assertSame($head, $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'"));
        self::assertSame(1, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_credit_note'));
        self::assertSame('credit_note', $first->outcome);
    }

    public function test_partial_cancel_refuses_metered_per_unit_usage_without_snapshot(): void
    {
        $base = $this->price(version: 2);
        $data = [...get_object_vars($base), 'strategyId' => StrategyId::PerUnit, 'terms' => new PerUnitTerms(unitPrice: '1'), 'termsDigest' => 'draft'];
        $draft = FixtureArguments::construct(class: PriceVersion::class, arguments: $data);
        $data['termsDigest'] = CanonicalJson::termsDigest($draft);
        $this->application()->make(PriceVersionStore::class)->publish(price: FixtureArguments::construct(class: PriceVersion::class, arguments: $data), commandKey: 'per-unit-price');
        $booked = $this->book($this->prepared(priceVersion: 2));
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $head = $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'");
        try {
            $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::PartialCancel, amendment: new HostAmendment(amendmentRef: 'cancel', revision: 1, effectiveAt: new DateTimeImmutable('2026-10-16Z'), prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null), reasonCode: 'cancel', releasePolicy: ReleasePolicy::OpenOnly));
            self::fail('Metered per-unit usage was prorated');
        } catch (ProblemException $error) {
            self::assertStringContainsString('amendment_unsupported', $error->detail);
        }
        self::assertSame($head, $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'"));
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_partial_cancel_accepts_retained_approved_quantity_snapshot(): void
    {
        $base = $this->price(version: 2);
        $data = [...get_object_vars($base), 'strategyId' => StrategyId::PerUnit, 'unit' => 'seat', 'terms' => new PerUnitTerms(unitPrice: '1'), 'termsDigest' => 'draft'];
        $data['termsDigest'] = CanonicalJson::termsDigest(FixtureArguments::construct(class: PriceVersion::class, arguments: $data));
        $this->application()->make(PriceVersionStore::class)->publish(price: FixtureArguments::construct(class: PriceVersion::class, arguments: $data), commandKey: 'approved-price');
        /** @var \stdClass&object{script: list<object{args: object{json_text: string}}>} $vector */
        $vector = json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/usage/contracts/quantity.json'), false, 64, JSON_THROW_ON_ERROR);
        $raw = json_decode($vector->script[0]->args->json_text, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $raw);
        $raw->sellerRef = 'seller-a';
        $raw->attribution = UsageFixtures::fact()->attribution;
        $raw->quantity = '100';
        $raw->unit = 'seat';
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $snapshot = $codec->decode($codec->canonicalize($raw));
        $request = $this->request(priceVersion: 2);
        $components = $request->components;
        $components[0]['quantitySnapshot'] = $snapshot;
        $invalid = clone $raw;
        $invalid->quantity = '99';
        $invalid->digest = $codec->digest($invalid);
        $invalidComponents = $components;
        $invalidComponents[0]['quantitySnapshot'] = $codec->decode($codec->canonicalize($invalid));
        $invalidResult = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $invalidComponents]));
        self::assertInstanceOf(RatingHold::class, $invalidResult);
        self::assertStringContainsString('quantity_snapshot_invalid', $invalidResult->detail);
        $request = FixtureArguments::construct(class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components]);
        $prepared = $this->application()->make(RatingPreparer::class)->prepare($request);
        self::assertInstanceOf(PreparedRating::class, $prepared);
        $booked = $this->book($prepared);
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-10-16Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $service = $this->application()->make(RatingCorrection::class);
        $correction = $service->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::PartialCancel, amendment: new HostAmendment(amendmentRef: 'cancel', revision: 1, effectiveAt: $date, prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null), reasonCode: 'cancel', releasePolicy: ReleasePolicy::OpenOnly));
        self::assertInstanceOf(PreparedCorrection::class, $correction);
        self::assertSame('-5161', $correction->computation->deltaGrossMinor);
        $retained = $correction->inputs->aggregates[0]->quantitySnapshot;
        self::assertNotNull($retained);
        self::assertSame($raw->digest, $retained['digest'] ?? null);
        $modified = $correction->inputs->aggregates[0]->quantitySnapshot;
        $modified['quantity'] = '99';
        $forgedAggregate = FixtureArguments::construct(class: FrozenAggregate::class, arguments: [...get_object_vars($correction->inputs->aggregates[0]), 'quantitySnapshot' => $modified]);
        $forgedInputs = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($correction->inputs), 'aggregates' => [$forgedAggregate]]);
        $forged = FixtureArguments::construct(class: PreparedCorrection::class, arguments: [...get_object_vars($correction), 'inputs' => $forgedInputs]);
        try {
            $service->apply(new ApplyCorrection(prepared: $forged, operationKey: 'forged-snapshot'));
            self::fail('Changed retained quantity evidence was accepted');
        } catch (ProblemException $error) {
            self::assertStringContainsString('correction_digest_mismatch', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame('credit_note', $service->apply(new ApplyCorrection(prepared: $correction, operationKey: 'approved-cancel'))->outcome);
        self::assertSame(5161, $this->value('SELECT credited_minor FROM billium_obligation WHERE obligation_ref=?', [$booked->obligationRef]));
    }

    public function test_untrusted_prepared_parent_obligation_is_rechecked(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        $forged = FixtureArguments::construct(class: PreparedCorrection::class, arguments: [...get_object_vars($prepared), 'originalObligationRef' => 'unrelated']);
        try {
            $correction->apply(new ApplyCorrection(prepared: $forged, operationKey: 'forged'));
            self::fail('Unrelated parent obligation was accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.correction_digest_mismatch', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
    }

    public function test_missing_and_unsupported_tax_policies_hold_before_writes(): void
    {
        $this->book($this->prepared());
        $date = new DateTimeImmutable('2026-11-15Z');
        $command = new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date);
        $result = $this->application()->make(RatingCorrection::class)->prepare($command);
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertSame('billium.rating.tax_policy_missing', $result->detail);
        $port = $this->correctionTax(rate: '0', version: '1', treatment: 'unregistered');
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $result = $this->application()->make(RatingCorrection::class)->prepare($command);
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertSame('billium.rating.tax_policy_unsupported', $result->detail);
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_heterogeneous_component_tax_rates_hold_before_financial_correction(): void
    {
        $this->application()->instance(UsageAttribution::class, new class implements UsageAttribution
        {
            public function resolve(Caller $caller, UsageFactV2 $fact): AttributionSnapshot
            {
                return new AttributionSnapshot(sellerRef: $caller->sellerRef, attribution: $fact->attribution, mappingRef: 'mapping-1', validFrom: '2026-10-01T00:00:00Z', validUntil: '2026-11-01T00:00:00Z', periodRef: 'october-2026', periodStart: '2026-10-01T00:00:00Z', periodEnd: '2026-11-01T00:00:00Z');
            }
        });
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'component-two-fact';
            $fact->attribution->componentRef = 'component-2';
            $fact->value->quantity = '100';
        }));
        $firstWindow = $this->window();
        $group = $firstWindow->groupKey;
        $group['componentRef'] = 'component-2';
        $window = new WindowSelector(sellerRef: $firstWindow->sellerRef, environment: $firstWindow->environment, metricRef: $firstWindow->metricRef, metricVersion: $firstWindow->metricVersion, groupKey: $group, startMicros: $firstWindow->startMicros, endMicros: $firstWindow->endMicros);
        $secondRef = FixtureValues::text($this->aggregation()->freeze(new FreezeAggregate(window: $window))->body()['snapshotRef']);
        $port = new class implements TaxSnapshotPort
        {
            public int $correctionCalls = 0;

            public function resolve(TaxResolutionRequest $request): TaxSnapshot
            {
                $tax = new TestTaxPort;
                $tax->rate = $request->componentRef === 'component-1' ? '0.20' : '0.10';

                return $tax->resolve($request);
            }

            public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
            {
                $this->correctionCalls++;
                throw new RuntimeException('heterogeneous policy must not be resolved');
            }
        };
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $priceData = [...get_object_vars($this->price(version: 2)), 'strategyId' => StrategyId::PerUnit, 'terms' => new PerUnitTerms(unitPrice: '1'), 'termsDigest' => 'draft'];
        $priceData['termsDigest'] = CanonicalJson::termsDigest(FixtureArguments::construct(class: PriceVersion::class, arguments: $priceData));
        $this->application()->make(PriceVersionStore::class)->publish(price: FixtureArguments::construct(class: PriceVersion::class, arguments: $priceData), commandKey: 'two-tax-price');
        $request = $this->request(priceVersion: 2);
        $components = [...$request->components, ['componentRef' => 'component-2', 'snapshotRef' => $secondRef, 'priceRef' => 'api', 'priceVersion' => 2]];
        $prepared = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components]));
        self::assertInstanceOf(PreparedRating::class, $prepared);
        self::assertSame('3000', $prepared->draft->taxMinor);
        $this->book($prepared);
        $firstDigest = FixtureValues::text($this->queries()->snapshot($this->snapshotRef)['snapshotDigest']);
        $secondDigest = FixtureValues::text($this->queries()->snapshot($secondRef)['snapshotDigest']);
        foreach ([1, 2] as $component) {
            $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact) use ($component): void {
                $fact->factId = 'late-tax-'.$component;
                $fact->attribution->componentRef = 'component-'.$component;
                $fact->value->quantity = '50';
            }));
        }
        $firstLate = $this->aggregation()->freeze(new FreezeAggregate(window: $firstWindow, expectedHeadDigest: $firstDigest))->body();
        $secondLate = $this->aggregation()->freeze(new FreezeAggregate(window: $window, expectedHeadDigest: $secondDigest))->body();
        self::assertSame('150.000000000', $this->queries()->snapshot(FixtureValues::text($firstLate['snapshotRef']))['quantity']);
        self::assertSame('150.000000000', $this->queries()->snapshot(FixtureValues::text($secondLate['snapshotRef']))['quantity']);
        $head = $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'");
        $held = $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: null, correctionEffectiveDate: new DateTimeImmutable('2026-11-15Z')));
        self::assertInstanceOf(RatingHold::class, $held);
        self::assertSame('billium.rating.tax_policy_unsupported', $held->detail);
        self::assertSame(0, $port->correctionCalls);
        self::assertSame($head, $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'"));
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_zero_delta_advances_frontier_without_ledger_effect(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.20', version: '1');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $fault = new LedgerFault($this->application()->make(LedgerParticipants::class));
        $this->application()->instance(LedgerParticipants::class, $fault);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        self::assertSame('0', $prepared->computation->deltaGrossMinor);
        self::assertSame('none', $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'zero'))->outcome);
        self::assertSame(0, $fault->calls);
        self::assertSame(1, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(1, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_mixed_sign_tax_and_usage_delta_is_held(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $originalDigest = FixtureValues::text($this->queries()->snapshot($this->snapshotRef)['snapshotDigest']);
        $this->usage()->submitUsageFact(UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'fact-2';
            $fact->value->quantity = '50';
        }));
        $this->freeze($originalDigest);
        $port = $this->correctionTax(rate: '0', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $result = $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertSame('billium.rating.correction_mixed_sign', $result->detail);
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_tax_policy_result_with_changed_taxable_base_is_held(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.25', version: '2', mode: TaxMode::Inclusive);
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $result = $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: ReleasePolicy::OpenOnly, correctionEffectiveDate: $date));
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertSame('billium.rating.tax_policy_unsupported', $result->detail);
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_negative_participant_failure_rolls_back_child_and_credit_note(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '31.00'), commandKey: 'fixed-price');
        $booked = $this->book($this->prepared(priceVersion: 2));
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-10-16Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $fault = new LedgerFault(inner: $this->application()->make(LedgerParticipants::class), failure: 'credit_rollback');
        $this->application()->instance(LedgerParticipants::class, $fault);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::PartialCancel, amendment: new HostAmendment(amendmentRef: 'cancel', revision: 1, effectiveAt: $date, prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null), reasonCode: 'cancel', releasePolicy: ReleasePolicy::OpenOnly));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        try {
            $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'negative-fault'));
            self::fail('Credit-note fault did not abort correction');
        } catch (RuntimeException $error) {
            self::assertSame('test after successful credit-note participant', $error->getMessage());
        }
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(0, $this->value('SELECT credited_minor FROM billium_obligation WHERE obligation_ref=?', [$booked->obligationRef]));
        self::assertSame(1, $this->rows('billium_rating_rated_basis'));
    }

    public function test_full_reversal_after_positive_correction_credits_the_entire_effective_basis(): void
    {
        $booked = $this->book($this->prepared());
        self::assertNotNull($booked->obligationRef);
        $this->issueInvoice($booked->obligationRef);
        $originalFact = UsageFixtures::fact(static function ($fact): void {
            $fact->value->quantity = '100';
        });
        $addedFact = UsageFixtures::fact(static function ($fact): void {
            $fact->factId = 'fact-2';
            $fact->value->quantity = '200';
        });
        $digest = FixtureValues::text($this->queries()->snapshot($this->snapshotRef)['snapshotDigest']);
        $this->usage()->submitUsageFact($addedFact);
        $newSnapshot = $this->freeze($digest);
        $port = $this->correctionTax(rate: '0', version: '1');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $command = new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'late', releasePolicy: ReleasePolicy::OpenOnly, correctionEffectiveDate: $date);
        $positive = $correction->prepare($command);
        self::assertInstanceOf(PreparedCorrection::class, $positive);
        self::assertSame('16000', $positive->computation->deltaGrossMinor);
        $positiveReceipt = $correction->apply(new ApplyCorrection(prepared: $positive, operationKey: 'increase'));
        $this->usage()->submitUsageFact(UsageFixtures::correction(previous: $originalFact, quantity: '0'));
        $secondCorrection = UsageFixtures::correction(previous: $addedFact, quantity: '0');
        $raw = json_decode(json_encode($secondCorrection->raw, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $raw);
        self::assertInstanceOf(\stdClass::class, $raw->correction);
        $raw->correction->correctionRef = 'correction-added-2';
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $this->usage()->submitUsageFact(FixtureValues::instance($codec->decode($codec->canonicalize($raw)), UsageFactV2::class));
        $this->freeze(FixtureValues::text($newSnapshot['snapshotDigest']));
        $hold = $correction->prepare($command);
        self::assertInstanceOf(RatingHold::class, $hold);
        self::assertSame('billium.rating.invoice_not_issued', $hold->detail);
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertNotNull($positiveReceipt->obligationRef);
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account');
        $ledger = $this->application()->make(Ledger::class);
        self::assertTrue($ledger->createInvoice(new CreateInvoice(commandKey: 'draft-child', scope: $scope, invoiceRef: 'invoice-child', customerRef: 'original-customer', lines: [new InvoiceLineInput(obligationRef: $positiveReceipt->obligationRef)]))->accepted);
        self::assertTrue($ledger->issueInvoice(new IssueInvoice(commandKey: 'issue-child', scope: $scope, invoiceRef: 'invoice-child'))->accepted);
        $invoiceContentSql = "SELECT jsonb_build_object('invoice', to_jsonb(i) - ARRAY['status','revision','allocated_minor','credited_minor','open_minor']::text[], 'lines', (SELECT jsonb_agg(to_jsonb(l) ORDER BY l.id) FROM billium_invoice_line l WHERE l.invoice_id=i.id))::text FROM billium_invoice i WHERE i.invoice_ref=?";
        $originalContent = $this->value($invoiceContentSql, ['invoice-1']);
        $childContent = $this->value($invoiceContentSql, ['invoice-child']);
        $negative = $correction->prepare($command);
        self::assertInstanceOf(PreparedCorrection::class, $negative);
        self::assertSame('-26000', $negative->computation->deltaGrossMinor);
        $participant = $this->application()->make(CorrectionCreditParticipants::class);
        $fault = new readonly class($participant) implements CorrectionCreditParticipants
        {
            public function __construct(private CorrectionCreditParticipants $inner) {}

            public function issue(TransactionContext $context, array $commands): void
            {
                $this->inner->issue($context, $commands);
                throw new RuntimeException('multi-credit fault');
            }
        };
        $this->application()->instance(CorrectionCreditParticipants::class, $fault);
        $failing = $this->application()->make(RatingCorrection::class);
        $before = $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'");
        try {
            $failing->apply(new ApplyCorrection(prepared: $negative, operationKey: 'full-reversal-fault'));
            self::fail('Multi-credit fault did not abort the root');
        } catch (RuntimeException $error) {
            self::assertSame('multi-credit fault', $error->getMessage());
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(1, $this->rows('billium_rating_correction'));
        self::assertSame($before, $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'"));
        $receipt = $correction->apply(new ApplyCorrection(prepared: $negative, operationKey: 'full-reversal'));
        self::assertSame('credit_note', $receipt->outcome);
        $queries = $this->application()->make(LedgerQueries::class);
        $group = $queries->receipt('credit-note:'.$receipt->creditNoteRef);
        self::assertSame($receipt->creditNoteRef, $this->value("SELECT payload->>'creditNoteRef' FROM billium_outbox_command WHERE business_ref=?", [$receipt->correctionRef]));
        self::assertIsArray($group['parts']);
        self::assertCount(2, $group['parts']);
        $amounts = [];
        foreach ($group['parts'] as $part) {
            self::assertIsArray($part);
            $resolved = $queries->receipt(self::text($part['receiptRef']));
            self::assertSame($part['receipt'], $resolved);
            $lines = self::arr($resolved['lines']);
            $line = self::arr($lines[0]);
            $amounts[] = $line['amount_minor'];
        }
        sort($amounts);
        self::assertSame(['10000', '16000'], $amounts);
        try {
            $queries->receipt('credit-note:'.$receipt->creditNoteRef.'.0000000000000000');
            self::fail('An incomplete aggregate receipt resolved');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ReferenceUnknown, $error->problem);
        }
        $replay = $correction->apply(new ApplyCorrection(prepared: $negative, operationKey: 'full-reversal-replay'));
        self::assertSame($receipt->creditNoteRef, $replay->creditNoteRef);
        self::assertSame($group, $queries->receipt('credit-note:'.$replay->creditNoteRef));
        self::assertSame(2, $this->rows('billium_credit_note'));
        self::assertSame(10000, $this->value('SELECT credited_minor FROM billium_obligation WHERE obligation_ref=?', [$booked->obligationRef]));
        self::assertSame(16000, $this->value('SELECT credited_minor FROM billium_obligation WHERE obligation_ref=?', [$positiveReceipt->obligationRef]));
        self::assertSame($originalContent, $this->value($invoiceContentSql, ['invoice-1']));
        self::assertSame($childContent, $this->value($invoiceContentSql, ['invoice-child']));
    }

    public function test_failure_after_ledger_participant_rolls_back_child_basis_and_frontier(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $fault = new LedgerFault($this->application()->make(LedgerParticipants::class));
        $this->application()->instance(LedgerParticipants::class, $fault);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        $before = $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'");
        try {
            $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'rollback'));
            self::fail('Ledger fault did not abort correction');
        } catch (RuntimeException $error) {
            self::assertSame('test after successful ledger participant', $error->getMessage());
        }
        self::assertSame(1, $fault->calls);
        self::assertSame($before, $this->value("SELECT head_digest FROM billium_rating_source_frontier WHERE frontier_key LIKE 'occurrence:%'"));
        self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(0, $this->rows('billium_rating_correction'));
        self::assertSame(1, $this->rows('billium_rating_rated_basis'));
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    public function test_five_identical_corrections_create_one_financial_delta(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $prepared = $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        $results = ConcurrencyStand::run(scenario: CorrectionScenario::class, workers: 5, args: ['prepared' => base64_encode(serialize($prepared))]);
        $bodies = array_map(self::ok(...), $results);
        self::assertCount(5, array_unique(array_map(self::integer(...), array_column($bodies, 'pid'))));
        $outcomes = array_count_values(array_map(self::text(...), array_column(array_column($bodies, 'body'), 'outcome')));
        self::assertSame(1, $outcomes['obligation'] ?? 0);
        self::assertSame(4, $outcomes['existing'] ?? 0);
        self::assertSame(1, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(1, $this->rows('billium_rating_correction'));
        self::assertSame(2, $this->rows('billium_obligation'));
    }

    public function test_competing_corrections_reprepare_against_last_effective_basis(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $date = new DateTimeImmutable('2026-11-15Z');
        $firstPort = $this->correctionTax(rate: '0.25', version: '2', treatment: 'original_rate');
        $secondPort = $this->correctionTax(rate: '0.30', version: '2', treatment: 'current_legal_rate');
        $this->publishPolicy(port: $firstPort, date: $date);
        $command = new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date);
        $this->application()->instance(TaxSnapshotPort::class, $firstPort);
        $first = $this->application()->make(RatingCorrection::class)->prepare($command);
        $this->application()->instance(TaxSnapshotPort::class, $secondPort);
        $second = $this->application()->make(RatingCorrection::class)->prepare($command);
        self::assertInstanceOf(PreparedCorrection::class, $first);
        self::assertInstanceOf(PreparedCorrection::class, $second);
        $results = ConcurrencyStand::run(scenario: CorrectionScenario::class, workers: 2, args: ['alternatives' => [base64_encode(serialize($first)), base64_encode(serialize($second))]]);
        $bodies = array_map(self::ok(...), $results);
        self::assertSame(1, count(array_filter($bodies, static fn (array $body): bool => $body['accepted'] === true)));
        self::assertSame(['billium.rating.correction_frontier_stale'], array_column(array_filter($bodies, static fn (array $body): bool => ! $body['accepted']), 'detail'));
        $correction = $this->application()->make(RatingCorrection::class);
        $fresh = $correction->prepare($command);
        self::assertInstanceOf(PreparedCorrection::class, $fresh);
        $correction->apply(new ApplyCorrection(prepared: $fresh, operationKey: 'reprepare'));
        self::assertSame(1000, $this->value('SELECT sum(delta_gross_minor)::bigint FROM billium_rating_correction'));
    }

    /** @return list<array{string}> */
    public static function concurrentActions(): array
    {
        return [['issue'], ['revise']];
    }

    #[DataProvider('concurrentActions')]
    public function test_correction_serializes_with_invoice_issue_and_schedule_amendment(string $action): void
    {
        $this->tax->rate = '0.20';
        $booked = $this->book($this->prepared());
        self::assertNotNull($booked->obligationRef);
        if ($action === 'issue') {
            $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account');
            self::assertTrue($this->application()->make(Ledger::class)->createInvoice(new CreateInvoice(commandKey: 'draft', scope: $scope, invoiceRef: 'invoice-1', customerRef: 'original-customer', lines: [new InvoiceLineInput(obligationRef: $booked->obligationRef)]))->accepted);
        }
        $port = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $prepared = $this->application()->make(RatingCorrection::class)->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        $results = ConcurrencyStand::run(scenario: CorrectionScenario::class, workers: 5, args: ['prepared' => base64_encode(serialize($prepared)), $action => true]);
        $bodies = array_map(self::ok(...), $results);
        self::assertCount(5, array_filter($bodies, static fn (array $body): bool => $body['accepted'] === true));
        self::assertSame(1, $this->rows('billium_schedule_billing_correction'));
        self::assertSame(1, $this->rows('billium_rating_correction'));
        self::assertSame(2, $this->rows('billium_obligation'));
        if ($action === 'issue') {
            self::assertSame('issued', $this->value("SELECT status FROM billium_invoice WHERE invoice_ref='invoice-1'"));
            self::assertSame(12000, $this->value("SELECT total_minor FROM billium_invoice WHERE invoice_ref='invoice-1'"));
        }
    }

    public function test_correction_owned_faults_rollback_all_participants(): void
    {
        $this->tax->rate = '0.20';
        $this->book($this->prepared());
        $port = $this->correctionTax(rate: '0.25', version: '2');
        $date = new DateTimeImmutable('2026-11-15Z');
        $this->publishPolicy(port: $port, date: $date);
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $correction = $this->application()->make(RatingCorrection::class);
        $prepared = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, kind: CorrectionKind::LateUsage, amendment: null, reasonCode: 'tax', releasePolicy: null, correctionEffectiveDate: $date));
        self::assertInstanceOf(PreparedCorrection::class, $prepared);
        foreach ([['billium_schedule_billing_correction', 'AFTER INSERT'], ['billium_rating_rated_basis', 'AFTER INSERT'], ['billium_rating_correction_booking', 'AFTER INSERT'], ['billium_rating_source_frontier', 'AFTER UPDATE'], ['billium_outbox_command', 'AFTER INSERT']] as [$table, $timing]) {
            $this->owner()->unprepared("CREATE FUNCTION public.billium_test_correction_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'correction fault'; END \$\$");
            $this->owner()->unprepared("CREATE TRIGGER billium_test_correction_fault {$timing} ON {$table} FOR EACH ROW EXECUTE FUNCTION public.billium_test_correction_fault()");
            try {
                $correction->apply(new ApplyCorrection(prepared: $prepared, operationKey: 'fault-'.$table));
                self::fail('Fault did not abort correction');
            } catch (QueryException $error) {
                self::assertStringContainsString('correction fault', $error->getMessage());
            } finally {
                $this->owner()->unprepared("DROP TRIGGER billium_test_correction_fault ON {$table}; DROP FUNCTION public.billium_test_correction_fault()");
            }
            self::assertSame(0, $this->rows('billium_schedule_billing_correction'));
            self::assertSame(0, $this->rows('billium_rating_correction'));
            self::assertSame(1, $this->rows('billium_rating_rated_basis'));
            self::assertSame(1, $this->rows('billium_obligation'));
            self::assertSame(0, $this->rows('billium_outbox_command'));
        }
    }

    private function correctionTax(string $rate, string $version, string $treatment = 'current_legal_rate', ?TaxMode $mode = null): TaxSnapshotPort
    {
        return new readonly class($rate, $version, $treatment, $mode) implements TaxSnapshotPort
        {
            public function __construct(private string $rate, private string $version, private string $treatment, private ?TaxMode $mode) {}

            public function resolve(TaxResolutionRequest $request): TaxSnapshot
            {
                throw new LogicException('Only correction resolution is expected');
            }

            public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
            {
                $from = new DateTimeImmutable('2026-01-01Z');
                $rate = new TaxSnapshot(snapshotRef: 'correction-tax:'.$this->version, kind: TaxSnapshotKind::Correction, mode: $this->mode ?? ($this->rate === '0' ? TaxMode::NoneDeclared : TaxMode::Exclusive), jurisdiction: 'TEST', taxCode: 'test-tax', rate: $this->rate, effectiveDate: $from, taxPoint: $request->originalTaxPoint, noTaxReason: $this->rate === '0' ? 'test-exempt' : null, resolver: 'host', resolverVersion: '1', inputDigest: $request->inputDigest, evidenceDigest: 'rate-evidence');

                return new TaxCorrectionPolicySnapshot(policyRef: 'policy', policyVersion: $this->version, treatment: $this->treatment, selectedRate: $rate, effectiveFrom: $from, effectiveTo: null, inputDigest: $request->inputDigest, outputDigest: 'output', resolverVersion: '1');
            }
        };
    }

    private function publishPolicy(TaxSnapshotPort $port, DateTimeImmutable $date): void
    {
        $dummy = new TaxCorrectionRequest(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $this->occurrenceRef, originalTaxSnapshotRef: 'original', originalTaxSnapshotDigest: 'digest', originalTaxPoint: $date, correctionEffectiveDate: $date, reasonCode: 'tax', jurisdiction: 'TEST', originalTaxableBaseMinor: '10000', correctedTaxableBaseMinor: '10000', inputDigest: 'input');
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish(policy: $port->resolveCorrection($dummy), commandKey: 'policy-v2');
    }

    private function issueInvoice(?string $obligation): void
    {
        self::assertNotNull($obligation);
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'original-account');
        $ledger = $this->application()->make(Ledger::class);
        self::assertTrue($ledger->createInvoice(new CreateInvoice(commandKey: 'draft', scope: $scope, invoiceRef: 'invoice-1', customerRef: 'original-customer', lines: [new InvoiceLineInput(obligationRef: $obligation)]))->accepted);
        self::assertTrue($ledger->issueInvoice(new IssueInvoice(commandKey: 'issue', scope: $scope, invoiceRef: 'invoice-1'))->accepted);
    }
}
