<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Integration\Support\P4TaxPolicy;
use Billium\Core\Tests\Support\Extensions\FixedRuleV2;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\BillingAccountScope;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\InvoiceLineInput;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Contracts\Installation;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
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
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
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
use Billium\Usage\Internal\UsageServiceProvider;
use DateTimeImmutable;

final class P4ModuleCompositionTest extends CompositionTestCase
{
    public function test_fixed_partial_cancellation_works_without_and_with_usage_installed(): void
    {
        $tax = new TestTaxPort;
        $tax->rate = '0.20';
        $prepared = $this->prepared(tax: $tax);
        config(['billium.modules.enabled' => ['platform', 'ledger', 'rating', 'schedule']]);
        $this->application()->register(LedgerServiceProvider::class);
        $this->migrateModules();
        $this->application()->forgetInstance(DatabaseInstallation::class);
        $this->application()->make(Installation::class)->init('inst-test');
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
            reasonCode: 'cancel', jurisdiction: 'TEST', originalTaxableBaseMinor: '4900', correctedTaxableBaseMinor: '2450', inputDigest: 'publication');
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish(policy: $port->resolveCorrection($request), commandKey: 'correction-policy');
        foreach ([false, true] as $withUsage) {
            if ($withUsage) {
                config(['billium.modules.enabled' => ['platform', 'ledger', 'rating', 'schedule', 'usage'], 'billium.usage.enabled' => true]);
                $this->application()->register(UsageServiceProvider::class);
                $this->migrateModules();
                $this->application()->forgetInstance(DatabaseInstallation::class);
                $this->application()->make(Installation::class)->init('inst-test');
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
            self::assertSame($withUsage ? '-1470' : '-2940', $cancel->computation->deltaGrossMinor);
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
        config(['billium.modules.enabled' => ['platform', 'ledger', 'rating', 'schedule']]);
        $this->application()->register(LedgerServiceProvider::class);
        $this->migrateModules();
        $this->application()->forgetInstance(DatabaseInstallation::class);
        $this->application()->make(Installation::class)->init('inst-test');
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
