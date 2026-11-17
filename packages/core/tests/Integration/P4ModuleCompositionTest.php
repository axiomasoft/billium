<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\QuantitySnapshot;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Integration\Support\P4TaxPolicy;
use Billium\Core\Tests\Support\Extensions\FixedRuleV2;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Laravel\LedgerServiceProvider;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\RatingQuoter;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\HostAmendment;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\QuoteRequest;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Enums\ChargeKind;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\ProrationPolicyId;
use Billium\Rating\Enums\ReleasePolicy;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Rating\Tests\Support\TestTaxPort;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Tests\Support\Terms;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Laravel\UsageServiceProvider;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4ModuleCompositionTest extends CompositionTestCase
{
    /** @return iterable<string, array{StrategyId, bool, string}> */
    public static function approvedQuantityCases(): iterable
    {
        foreach ([StrategyId::PerUnit, StrategyId::TieredGraduated, StrategyId::TieredVolume] as $strategy) {
            foreach (['without-usage' => [false, 'seats-1'], 'with-usage' => [true, 'seats-1'], 'fixed-selector' => [true, 'fixed']] as $case => [$usage, $selector]) {
                yield $strategy->value.'-'.$case => [$strategy, $usage, $selector];
            }
        }
    }

    #[DataProvider('approvedQuantityCases')]
    public function test_approved_quantity_prepares_quotes_books_and_replays_without_metered_usage(StrategyId $strategy, bool $withUsage, string $selector): void
    {
        $request = $this->approvedQuantityRequest(strategy: $strategy, withUsage: $withUsage, selector: $selector);
        $preparer = $this->application()->make(RatingPreparer::class);
        $prepared = $preparer->prepare($request);
        self::assertInstanceOf(PreparedRating::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('approved_quantity/1', $prepared->inputs->aggregates[0]->snapshot['kind']);
        self::assertSame('10', $prepared->inputs->aggregates[0]->quantity);
        self::assertSame('1000', $prepared->draft->grossMinor);
        $quoter = $this->application()->make(RatingQuoter::class);
        $quote = $quoter->quote(new QuoteRequest($prepared->inputs));
        self::assertSame('1000', $quote->grossMinor);
        $booking = $this->application()->make(RatingBooking::class);
        $receipt = $booking->book(new BookRatedBasis(prepared: $prepared, operationKey: 'approved-book'));
        self::assertSame(BookingOutcome::Unposted, $receipt->outcome);
        $retained = FrozenInputsCodec::decode(RatingRows::text($this->owner()->table('billium_rating_run')->value('frozen_inputs')));
        self::assertSame($quote->quoteDigest, $quoter->quote(new QuoteRequest($retained))->quoteDigest);
        foreach (['fixed', 'seats-1'] as $replaySelector) {
            $components = $request->components;
            $components[0]['snapshotRef'] = $replaySelector;
            $replay = $preparer->prepare(FixtureArguments::construct(class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components]));
            self::assertInstanceOf(PreparedRating::class, $replay, json_encode($replay, JSON_THROW_ON_ERROR));
            self::assertSame($prepared->draft->ratedDigest, $replay->draft->ratedDigest);
            self::assertSame($receipt->basisRef, $booking->book(new BookRatedBasis(prepared: $replay, operationKey: 'replay-'.$replaySelector))->basisRef);
        }
        if ($withUsage) {
            self::assertSame(0, $this->owner()->table('billium_usage_aggregate_snapshot')->count());
        } else {
            self::assertFalse($this->application()->bound(Usage::class));
        }
    }

    /** @return iterable<string, array{StrategyId}> */
    public static function zeroQuantityCases(): iterable
    {
        foreach ([StrategyId::PerUnit, StrategyId::TieredGraduated, StrategyId::TieredVolume] as $strategy) {
            yield $strategy->value => [$strategy];
        }
    }

    #[DataProvider('zeroQuantityCases')]
    public function test_approved_zero_quantity_at_a_nonzero_tariff_books_without_an_obligation(StrategyId $strategy): void
    {
        $request = $this->approvedQuantityRequest(strategy: $strategy);
        $quantity = $request->components[0]['quantitySnapshot'] ?? null;
        self::assertInstanceOf(QuantitySnapshot::class, $quantity);
        $codec = new Codec;
        $raw = RatingRows::map(json_decode($codec->encode($quantity), true, 64, JSON_THROW_ON_ERROR));
        $raw['quantity'] = '0';
        $raw['digest'] = $codec->digest($raw);
        $components = $request->components;
        $components[0]['quantitySnapshot'] = $codec->decode($codec->canonicalize($raw));
        $prepared = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(
            class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components,
                'noChargeReason' => 'approved_zero_quantity']));
        self::assertInstanceOf(PreparedRating::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('0', $prepared->draft->grossMinor);
        self::assertSame(ChargeKind::NoCharge, $prepared->draft->chargeKind);
        self::assertSame('approved_zero_quantity', $prepared->draft->noChargeReason);
        $this->attachModule('ledger', LedgerServiceProvider::class);
        $receipt = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(
            prepared: $prepared, operationKey: 'zero-quantity'));
        self::assertSame(BookingOutcome::NoCharge, $receipt->outcome);
        self::assertNull($receipt->obligationRef);
        self::assertSame(0, $this->owner()->table('billium_obligation')->count());
    }

    #[DataProvider('zeroQuantityCases')]
    public function test_a_nonzero_quantity_rounded_to_zero_still_requires_a_zero_tariff(StrategyId $strategy): void
    {
        $request = $this->approvedQuantityRequest(strategy: $strategy, unitPrice: '0.0001');
        $result = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(
            class: PrepareRating::class, arguments: [...get_object_vars($request), 'noChargeReason' => 'approved_zero_quantity']));
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertSame('billium.rating.zero_price_not_declared', $result->detail);
        self::assertSame(0, $this->owner()->table('billium_rating_run')->count());
    }

    public function test_a_pause_preserves_an_unposted_basis_but_refuses_its_later_posting(): void
    {
        $prepared = $this->prepared();
        $booking = $this->application()->make(RatingBooking::class);
        self::assertSame(BookingOutcome::Unposted, $booking->book(new BookRatedBasis(
            prepared: $prepared, operationKey: 'unposted-before-pause'))->outcome);
        $this->application()->make(OccurrenceService::class)->applyScheduleRevision(new ApplyScheduleRevision(
            sellerRef: 'seller-a', environment: 'test', scheduleRef: 'monthly', expectedRevision: 1,
            expectedRowVersion: 1, newRevision: 2, terms: Terms::make(['anchorLocal' => '2026-10-01T00:00:00',
                'priceVersionRef' => 'monthly@1', 'purpose' => 'recurring_charge', 'action' => 'pause']),
            effectiveFrom: new DateTimeImmutable('2026-10-01Z'), inputsDigest: $prepared->inputs->occurrenceInputsDigest,
            commandKey: 'pause-unposted'));
        self::assertSame('rated', $this->owner()->table('billium_schedule_billing_occurrence')->value('state'));
        $this->attachModule('ledger', LedgerServiceProvider::class);
        $booking = $this->application()->make(RatingBooking::class);
        try {
            $booking->book(new BookRatedBasis(prepared: $prepared, operationKey: 'post-after-pause'));
            self::fail('Paused unposted basis created an obligation');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.occurrence_not_bookable', $error->detail);
        }
        self::assertSame(1, $this->owner()->table('billium_rating_rated_basis')->count());
        self::assertSame(0, $this->owner()->table('billium_obligation')->count());
        self::assertSame(0, $this->owner()->table('billium_rating_booking')->count());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function forgedApprovedQuantityCases(): iterable
    {
        yield 'missing-approval' => ['quantitySnapshot', null];
        yield 'invalid-quantity-digest' => ['quantitySnapshot.digest', 'sha256:'.str_repeat('0', 64)];
        yield 'changed-quantity' => ['quantity', '11'];
        yield 'changed-scale' => ['quantityScale', 9];
        yield 'changed-reference' => ['snapshotRef', 'foreign'];
        yield 'changed-snapshot-digest' => ['snapshotDigest', 'sha256:'.str_repeat('0', 64)];
        yield 'changed-frontier' => ['completenessFrontierDigest', 'foreign'];
        yield 'changed-occurrence' => ['snapshot.occurrenceRef', 'foreign'];
        yield 'changed-input-digest' => ['snapshot.occurrenceInputsDigest', 'foreign'];
        yield 'changed-price' => ['snapshot.termsDigest', 'foreign'];
        yield 'changed-seller' => ['snapshot.window.sellerRef', 'foreign'];
        yield 'changed-environment' => ['snapshot.window.environment', 'live'];
        yield 'changed-account' => ['snapshot.window.group.billingAccountRef', 'foreign'];
        yield 'changed-agreement' => ['snapshot.window.group.agreementRevision', '2'];
        yield 'changed-component' => ['snapshot.window.group.componentRef', 'foreign'];
        yield 'changed-start' => ['snapshot.window.startMicros', 0];
        yield 'changed-end' => ['windowEndMicros', 0];
        yield 'changed-unit' => ['quantitySnapshot.unit', 'foreign'];
    }

    #[DataProvider('forgedApprovedQuantityCases')]
    public function test_approved_quantity_integrity_is_required_before_quote_or_booking(string $path, mixed $value): void
    {
        $request = $this->approvedQuantityRequest();
        $prepared = $this->application()->make(RatingPreparer::class)->prepare($request);
        self::assertInstanceOf(PreparedRating::class, $prepared);
        $raw = RatingRows::map(json_decode(CanonicalJson::encode($prepared->inputs), true, 64, JSON_THROW_ON_ERROR));
        Arr::set(array: $raw, key: 'aggregates.0.'.$path, value: $value);
        $inputs = FrozenInputsCodec::decode(CanonicalJson::encode($raw));
        foreach (['quote', 'book'] as $operation) {
            try {
                if ($operation === 'quote') {
                    $this->application()->make(RatingQuoter::class)->quote(new QuoteRequest($inputs));
                } else {
                    $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(
                        prepared: new PreparedRating(inputs: $inputs, draft: $prepared->draft), operationKey: 'forged-approved'));
                }
                self::fail('Forged approved quantity accepted');
            } catch (ProblemException $error) {
                self::assertStringStartsWith('billium.rating.', $error->detail);
            }
        }
        self::assertSame(0, $this->owner()->table('billium_rating_run')->count());
    }

    public function test_approved_quantity_with_a_valid_digest_must_match_the_occurrence_and_price_scope(): void
    {
        $request = $this->approvedQuantityRequest();
        $quantity = $request->components[0]['quantitySnapshot'] ?? null;
        self::assertInstanceOf(QuantitySnapshot::class, $quantity);
        $codec = new Codec;
        foreach (['sellerRef' => 'foreign', 'unit' => 'call', 'attribution.billingAccountRef' => 'foreign',
            'attribution.componentRef' => 'foreign', 'attribution.agreementRevision' => 2,
            'attribution.customerRef' => 'foreign', 'attribution.agreementRef' => 'foreign',
            'effectiveInterval.start' => '2026-10-02T00:00:00Z', 'effectiveInterval.end' => '2026-11-02T00:00:00Z'] as $path => $value) {
            $raw = RatingRows::map(json_decode($codec->encode($quantity), true, 64, JSON_THROW_ON_ERROR));
            Arr::set(array: $raw, key: $path, value: $value);
            $raw['digest'] = $codec->digest($raw);
            $components = $request->components;
            $components[0]['quantitySnapshot'] = $codec->decode($codec->canonicalize($raw));
            $result = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(
                class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components]));
            self::assertInstanceOf(RatingHold::class, $result, $path);
            self::assertSame('billium.rating.quantity_snapshot_invalid', $result->detail, $path);
        }
        self::assertSame(0, $this->owner()->table('billium_rating_run')->count());
    }

    private function approvedQuantityRequest(StrategyId $strategy = StrategyId::PerUnit, bool $withUsage = false, string $selector = 'seats-1', ?TaxSnapshotPort $tax = null, string $unitPrice = '1'): PrepareRating
    {
        if ($withUsage) {
            $this->attachModule('usage', UsageServiceProvider::class);
        }
        $base = $this->prepared(tax: $tax)->inputs;
        $terms = match ($strategy) {
            StrategyId::PerUnit => new PerUnitTerms(unitPrice: $unitPrice),
            StrategyId::TieredGraduated => new GraduatedTerms(tiers: [new Tier(index: 0, lower: '0', upper: null, unitPrice: $unitPrice, flatFee: '0')]),
            StrategyId::TieredVolume => new VolumeTerms(tiers: [new Tier(index: 0, lower: '0', upper: null, unitPrice: $unitPrice, flatFee: '0')]),
            default => throw new \LogicException('Unsupported quantity test strategy'),
        };
        $args = [...get_object_vars($base->prices[0]->snapshot), 'priceRef' => 'seats', 'strategyId' => $strategy, 'unit' => 'seat', 'terms' => $terms, 'termsDigest' => 'draft'];
        $args['termsDigest'] = CanonicalJson::termsDigest(FixtureArguments::construct(class: PriceVersion::class, arguments: $args));
        $this->application()->make(PriceVersionStore::class)->publish(price: FixtureArguments::construct(class: PriceVersion::class, arguments: $args), commandKey: 'publish-seats');
        /** @var \stdClass&object{script: list<object{args: object{json_text: string}}>} $vector */
        $vector = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/fixtures/vectors/usage/contracts/quantity.json'), false, 64, JSON_THROW_ON_ERROR);
        $raw = json_decode($vector->script[0]->args->json_text, false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $raw);
        self::assertInstanceOf(\stdClass::class, $raw->attribution);
        $raw->sellerRef = 'seller-a';
        $raw->attribution->customerRef = 'customer-a';
        $raw->attribution->billingAccountRef = 'account-a';
        $raw->attribution->componentRef = 'component-a';
        $raw->attribution->agreementRef = 'agreement-a';
        $codec = new Codec;
        $raw->digest = $codec->digest($raw);
        $quantity = $codec->decode($codec->canonicalize($raw));
        self::assertInstanceOf(QuantitySnapshot::class, $quantity);

        return new PrepareRating(occurrenceRef: $base->occurrenceRef, billingAccountRef: $base->billingAccountRef,
            attribution: $base->attribution, agreementRevision: $base->agreementRevision, occurrenceInputsDigest: $base->occurrenceInputsDigest,
            sourceDigest: $base->sourceDigest, components: [['componentRef' => 'component-a', 'snapshotRef' => $selector,
                'priceRef' => 'seats', 'priceVersion' => 1, 'quantitySnapshot' => $quantity]]);
    }

    /** @return iterable<string, array{bool}> */
    public static function syntheticCancellationCases(): iterable
    {
        yield 'fixed' => [false];
        yield 'approved-quantity' => [true];
    }

    #[DataProvider('syntheticCancellationCases')]
    public function test_synthetic_partial_cancellation_works_without_and_with_usage_installed(bool $approved): void
    {
        $tax = new TestTaxPort;
        $tax->rate = '0.20';
        $request = $approved ? $this->approvedQuantityRequest(tax: $tax) : null;
        $prepared = $request !== null
            ? $this->application()->make(RatingPreparer::class)->prepare($request)
            : $this->prepared(tax: $tax);
        self::assertInstanceOf(PreparedRating::class, $prepared);
        $this->attachModule('ledger', LedgerServiceProvider::class);
        $booked = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'fixed-book'));
        self::assertNotNull($booked->obligationRef);
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'account-a');
        $ledger = $this->application()->make(Ledger::class);
        self::assertTrue($ledger->createInvoice(new CreateInvoice(commandKey: 'draft', scope: $scope, invoiceRef: 'fixed-invoice',
            customerRef: 'customer-a', lines: [new InvoiceLineInput(obligationRef: $booked->obligationRef)]))->accepted);
        self::assertTrue($ledger->issueInvoice(new IssueInvoice(commandKey: 'issue', scope: $scope, invoiceRef: 'fixed-invoice'))->accepted);
        $port = new P4TaxPolicy;
        $this->application()->instance(TaxSnapshotPort::class, $port);
        $date = new DateTimeImmutable('2026-10-16T12:00:00Z');
        $request = new TaxCorrectionRequest(sellerRef: 'seller-a', environment: 'test', parentOccurrenceRef: $prepared->inputs->occurrenceRef,
            originalTaxSnapshotRef: 'original', originalTaxSnapshotDigest: 'digest', originalTaxPoint: $date, correctionEffectiveDate: $date,
            reasonCode: 'cancel', jurisdiction: 'TEST', originalTaxableBaseMinor: $approved ? '1000' : '4900',
            correctedTaxableBaseMinor: $approved ? '500' : '2450', inputDigest: 'publication');
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish(policy: $port->resolveCorrection($request), commandKey: 'correction-policy');
        foreach ([false, true] as $withUsage) {
            if ($withUsage) {
                $this->attachModule('usage', UsageServiceProvider::class);
            } else {
                self::assertFalse($this->application()->bound(Usage::class));
                self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_usage_fact')::text"));
            }
            $revision = $withUsage ? 2 : 1;
            $correction = $this->application()->make(RatingCorrection::class);
            $cancel = $correction->prepare(new PrepareCorrection(sellerRef: 'seller-a', environment: 'test',
                parentOccurrenceRef: $prepared->inputs->occurrenceRef, kind: CorrectionKind::PartialCancel,
                amendment: new HostAmendment(amendmentRef: 'fixed-cancel', revision: $revision,
                    effectiveAt: $withUsage ? new DateTimeImmutable('2026-10-08T18:00:00Z') : $date,
                    prorationPolicy: ProrationPolicyId::ElapsedSeconds, kind: 'partial_cancel', timezone: null),
                reasonCode: 'cancel', releasePolicy: ReleasePolicy::OpenOnly));
            self::assertInstanceOf(PreparedCorrection::class, $cancel, json_encode($cancel, JSON_THROW_ON_ERROR));
            self::assertSame($approved ? ($withUsage ? '-300' : '-600') : ($withUsage ? '-1470' : '-2940'), $cancel->computation->deltaGrossMinor);
            $receipt = $correction->apply(new ApplyCorrection(prepared: $cancel, operationKey: 'cancel-'.$revision));
            self::assertSame('credit_note', $receipt->outcome);
            self::assertSame($cancel->computation->deltaGrossMinor, $receipt->deltaGrossMinor);
            self::assertSame($revision, $this->owner()->table('billium_credit_note')->count());
            $replay = $correction->apply(new ApplyCorrection(prepared: $cancel, operationKey: 'cancel-replay-'.$revision));
            self::assertSame('existing', $replay->outcome);
            self::assertSame($receipt->creditNoteRef, $replay->creditNoteRef);
        }
    }

    private function prepared(int $strategyVersion = 1, ?TaxSnapshotPort $tax = null): PreparedRating
    {
        $this->application()->instance(TaxSnapshotPort::class, $tax ?? new TestTaxPort);
        $args = ['sellerRef' => 'seller-a', 'priceRef' => 'monthly', 'version' => 1, 'strategyId' => StrategyId::Fixed, 'strategyVersion' => $strategyVersion,
            'currency' => 'USD', 'currencyExponent' => 2, 'exponentVersion' => 'ISO4217-2026-10', 'unit' => 'component',
            'quantityRules' => new QuantityRules(quantityScale: 0, conversionNumerator: '1', conversionDenominator: '1', billingIncrement: '1', incrementMode: IncrementMode::None),
            'terms' => new FixedTerms(amount: '49', scope: 'per_component_occurrence'), 'roundingMode' => RoundingMode::HalfUp,
            'roundingStage' => RoundingStage::AfterTax, 'roundingPolicyVersion' => 'test/1', 'commitment' => null];
        $draft = new PriceVersion(...$args, termsDigest: 'draft');
        $price = new PriceVersion(...$args, termsDigest: CanonicalJson::termsDigest($draft));
        $this->application()->make(PriceVersionStore::class)->publish(price: $price, commandKey: 'publish-fixed');
        $schedule = $this->application()->make(OccurrenceService::class);
        $digest = 'sha256:'.str_repeat('a', 64);
        $schedule->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'monthly',
            expectedRevision: 0, expectedRowVersion: 0, newRevision: 1,
            terms: Terms::make(['anchorLocal' => '2026-10-01T00:00:00', 'priceVersionRef' => 'monthly@1', 'purpose' => 'recurring_charge']),
            effectiveFrom: new DateTimeImmutable('2026-01-01Z'), inputsDigest: $digest, commandKey: 'schedule'));
        $occurrence = $schedule->ensureOccurrence(new EnsureOccurrence(sellerRef: 'seller-a', environment: 'test', componentRef: 'component-a',
            purpose: OccurrencePurpose::RecurringCharge, periodStart: new DateTimeImmutable('2026-10-01Z'), periodEnd: new DateTimeImmutable('2026-11-01Z'),
            scheduleRef: 'monthly', scheduleRevision: 1, inputsDigest: $digest, commandKey: 'occurrence'));
        $result = $this->application()->make(RatingPreparer::class)->prepare(new PrepareRating(occurrenceRef: RatingRows::text($occurrence->body()['occurrenceRef']),
            billingAccountRef: 'account-a', attribution: '{"customerRef":"customer-a","agreementRef":"agreement-a"}',
            agreementRevision: '1', occurrenceInputsDigest: $digest, sourceDigest: 'host-terms:1',
            components: [['componentRef' => 'component-a', 'snapshotRef' => 'fixed', 'priceRef' => 'monthly', 'priceVersion' => 1]]));
        self::assertInstanceOf(PreparedRating::class, $result, json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function test_fresh_composition_quotes_and_freezes_fixed_price_without_ledger_or_usage(): void
    {
        foreach (['billium_obligation', 'billium_usage_fact', 'billium_invoice', 'billium_execution_operation'] as $table) {
            self::assertNull($this->owner()->scalar("SELECT to_regclass('public.{$table}')::text"));
        }
        self::assertFalse($this->application()->bound(Ledger::class));
        self::assertFalse($this->application()->bound(Usage::class));
        $prepared = $this->prepared();
        $quoter = $this->application()->make(RatingQuoter::class);
        $quote = $quoter->quote(new QuoteRequest($prepared->inputs));
        self::assertFalse($quote->financial);
        self::assertSame('4900', $quote->grossMinor);
        self::assertSame(0, $this->owner()->table('billium_rating_run')->count());
        $receipt = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'fixed-book'));
        self::assertSame(BookingOutcome::Unposted, $receipt->outcome);
        self::assertSame(1, $this->owner()->table('billium_rating_run')->count());
        self::assertSame('rated', $this->owner()->table('billium_schedule_billing_occurrence')->value('state'));
        $retained = FrozenInputsCodec::decode(RatingRows::text($this->owner()->table('billium_rating_run')->value('frozen_inputs')));
        self::assertSame($quote->quoteDigest, $quoter->quote(new QuoteRequest($retained))->quoteDigest);
        self::assertSame(['platform', 'rating', 'schedule'], $this->owner()->table('billium_module_state')->orderBy('module_id')->pluck('module_id')->all());
    }

    public function test_missing_strategy_refuses_before_a_financial_write(): void
    {
        $prepared = $this->prepared();
        $this->application()->instance(PricingStrategyRegistry::class, new StrategyCatalog(strategies: []));
        try {
            $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'missing-role'));
            self::fail('missing implementation booked');
        } catch (ProblemException $error) {
            self::assertStringContainsString('strategy_unknown', $error->detail);
        }
        self::assertSame(0, $this->owner()->table('billium_rating_run')->count());
        self::assertSame(0, $this->owner()->table('billium_idempotency_receipt')->where('command_key', 'missing-role')->count());
    }

    public function test_forged_fixed_input_cannot_bypass_usage_validation(): void
    {
        $prepared = $this->prepared();
        $inputs = FrozenInputsCodec::decode(str_replace('"quantity":"1"', '"quantity":"2"', CanonicalJson::encode($prepared->inputs)));
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('fixed_input_invalid');
        $this->application()->make(RatingQuoter::class)->quote(new QuoteRequest($inputs));
    }

    public function test_fixed_booking_adds_ledger_without_usage_legal_issue_render_or_execution(): void
    {
        $prepared = $this->prepared();
        $this->attachModule('ledger', LedgerServiceProvider::class);
        $receipt = $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'fixed-book'));
        self::assertSame(BookingOutcome::Booked, $receipt->outcome);
        self::assertSame(1, $this->owner()->table('billium_obligation')->count());
        self::assertSame(0, $this->owner()->table('billium_invoice')->count());
        self::assertSame(0, $this->owner()->table('billium_payment')->count());
        self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_usage_fact')::text"));
        self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_execution_operation')::text"));
    }

    public function test_trusted_rating_extension_negotiates_version_without_changing_migrations(): void
    {
        $before = $this->owner()->table('migrations')->orderBy('migration')->pluck('migration')->all();
        $this->application()->singleton(FixedRuleV2::class);
        $this->application()->tag(FixedRuleV2::class, 'billium.pricing_strategies');
        $prepared = $this->prepared(strategyVersion: 2);
        self::assertSame(2, $prepared->inputs->prices[0]->strategyVersion);
        self::assertSame('4900', $prepared->draft->grossMinor);
        self::assertSame(1, $this->application()->make(PricingStrategyRegistry::class)->resolve(id: StrategyId::Fixed, version: 1)->version());
        self::assertSame($before, $this->owner()->table('migrations')->orderBy('migration')->pluck('migration')->all());
        self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_obligation')::text"));
    }

    public function test_host_tax_extension_freezes_its_version_without_financial_or_schema_authority(): void
    {
        $host = new class implements TaxSnapshotPort
        {
            public int $calls = 0;

            public function resolve(TaxResolutionRequest $request): TaxSnapshot
            {
                $this->calls++;
                $snapshot = (new TestTaxPort)->resolve($request);

                return new TaxSnapshot(snapshotRef: $snapshot->snapshotRef, kind: $snapshot->kind, mode: $snapshot->mode,
                    jurisdiction: $snapshot->jurisdiction, taxCode: $snapshot->taxCode, rate: $snapshot->rate,
                    effectiveDate: $snapshot->effectiveDate, taxPoint: $snapshot->taxPoint, noTaxReason: $snapshot->noTaxReason,
                    resolver: 'host-extension', resolverVersion: '2', inputDigest: $snapshot->inputDigest, evidenceDigest: $snapshot->evidenceDigest);
            }

            public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
            {
                throw new \LogicException('Unsupported test correction policy');
            }
        };
        $before = $this->owner()->table('migrations')->pluck('migration')->all();
        $prepared = $this->prepared(tax: $host);
        self::assertSame('2', $prepared->inputs->taxes[0]->snapshot->resolverVersion);
        $this->application()->make(RatingBooking::class)->book(new BookRatedBasis(prepared: $prepared, operationKey: 'tax-extension'));
        $retained = FrozenInputsCodec::decode(RatingRows::text($this->owner()->table('billium_rating_run')->value('frozen_inputs')));
        self::assertSame('host-extension', $retained->taxes[0]->snapshot->resolver);
        self::assertSame('2', $retained->taxes[0]->snapshot->resolverVersion);
        self::assertSame($prepared->draft->ratedDigest, $this->application()->make(RatingQuoter::class)->quote(new QuoteRequest($retained))->quoteDigest);
        self::assertSame(1, $host->calls);
        self::assertSame($before, $this->owner()->table('migrations')->pluck('migration')->all());
        self::assertNull($this->owner()->scalar("SELECT to_regclass('public.billium_obligation')::text"));
    }

    public function test_absent_usage_capability_holds_metered_input_without_a_fixed_fallback(): void
    {
        $prepared = $this->prepared();
        $inputs = $prepared->inputs;
        $result = $this->application()->make(RatingPreparer::class)->prepare(new PrepareRating(
            occurrenceRef: $inputs->occurrenceRef, billingAccountRef: $inputs->billingAccountRef, attribution: $inputs->attribution,
            agreementRevision: $inputs->agreementRevision, occurrenceInputsDigest: $inputs->occurrenceInputsDigest, sourceDigest: $inputs->sourceDigest,
            components: [['componentRef' => 'component-a', 'snapshotRef' => 'agg_missing', 'priceRef' => 'monthly', 'priceVersion' => 1]]));
        self::assertInstanceOf(RatingHold::class, $result);
        self::assertSame('usage capability absent', $result->detail);
        self::assertSame(0, $this->owner()->table('billium_rating_run')->count());
    }
}
