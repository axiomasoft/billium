<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\CollectionParticipants;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RetireRecurringOwner;
use Billium\Collection\Data\RevocationReceipt;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Internal\Work\CollectionRecoveryWorker;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P7\Support\CollectionAdmissionScenario as Scenario;
use Billium\Core\Tests\P7\Support\CollectionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Provider;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\HostAmendment;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Tax\TaxCorrectionPolicySnapshot;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\ProrationPolicyId;
use Billium\Rating\Enums\ReleasePolicy;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Laravel\RatingServiceProvider;
use Billium\Rating\Tests\Support\TestTaxPort;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Laravel\ScheduleServiceProvider;
use Billium\Schedule\Tests\Support\Terms;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\DurableJournal;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('P7.5')]
final class CollectionCancellationTest extends CollectionTestCase
{
    private string $journal;

    private CapabilitySnapshot $capability;

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RatingServiceProvider::class, ScheduleServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection', 'rating', 'schedule']);
        $config->set('billium.rating.enabled', true);
        $config->set('billium.schedule.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->journal = Provider::tempPath();
        $base = SimulatedCapabilities::snapshot();
        $details = new CapabilityDetails(...array_replace(get_object_vars($base->details), [
            'savedMethod' => true, 'recurringConsent' => true,
        ]));
        $this->capability = new CapabilitySnapshot(...array_replace(get_object_vars($base), ['details' => $details]));
        Provider::seedMerchant($this->application(), $this->capability);
        Provider::host($this->application(), $this->journal, cap: $this->capability);
    }

    protected function tearDown(): void
    {
        if (isset($this->journal)) {
            UnknownResultTest::clean($this->journal);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function beforeDispatch(): iterable
    {
        yield 'step commit to admission' => [false];
        yield 'admission to guarded dispatch' => [true];
    }

    #[DataProvider('beforeDispatch')]
    public function test_host_cancellation_blocks_stale_delivery_and_preserves_no_effect_proof(bool $admitted): void
    {
        $this->recordRecurringObligation();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $ref = FixtureValues::text($step->stepAuthorizationRef);
        $payment = $this->paymentFromOutbox();
        $payments = $this->application()->make(RootPaymentCommands::class);
        if ($admitted) {
            $payments->admit(Provider::scope(), $payment)->orThrow();
        }
        $cancel = $this->hostCancel($ref);
        self::assertEquals($cancel, $this->hostCancel($ref));
        $this->refuses(ProblemCode::IdempotencyConflict, fn () => $this->hostCancel($ref, 'changed reason'));
        if ($admitted) {
            $this->refuses(ProblemCode::OperationFinancialHold, fn () => $this->dispatch('root-a'));
        } else {
            $refused = $payments->admit(Provider::scope(), $payment);
            self::assertSame(ProblemCode::OperationFinancialHold, $refused->problem);
            self::assertNotNull($refused->receiptId);
            self::assertSame($refused->receiptId, $payments->admit(Provider::scope(), $payment)->receiptId);
        }
        $participants = $this->application()->make(CollectionParticipants::class);
        $closure = $participants->closeRevoked(Provider::scope(), $ref);
        self::assertSame($closure, $participants->closeRevoked(Provider::scope(), $ref));
        self::assertSame(0, $this->providerCalls());
        self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame(1, $this->rows('billium_collection_guard_tombstone', 'target_authorization_ref=? AND target_business_intent_ref=?', [$ref, 'intent-a']));
        self::assertSame(1, $this->rows('billium_collection_step', "status='cancelled'"));
        self::assertSame(1, $this->rows('billium_collection_no_effect', 'closure_ref=?', [$closure]));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure', 'closure_ref=? AND proof_kind=?',
            [$closure, $admitted ? 'not_dispatched' : 'not_admitted']));
        self::assertSame(1, $this->rows('billium_settlement_target'));
        self::assertSame('closed_no_effect', $this->value('SELECT status FROM billium_settlement_intent'));
        self::assertSame('cancelled', $this->value('SELECT status FROM billium_collection_dunning_case'));
        $this->openCollectionCase('obligation-a', 'case-next', 2);
        $this->refuses(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->authorizeCollection(Scenario::command('next', 'case-next')));
        self::assertSame(1, $this->rows('billium_collection_authorization'));
    }

    public function test_retired_component_closes_unadmitted_work_after_epoch_change(): void
    {
        $this->recordRecurringObligation();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $ref = FixtureValues::text($step->stepAuthorizationRef);
        $app = $this->application();
        $this->authority->issueTransition('seal', 'seal', [1]);
        $this->installation()->seal('seal', 1);
        $this->authority->issueTransition('open', 'open', ['owner-2', 2, 2, 2, 'retirement']);
        $this->installation()->openSession('open', 'owner-2', 2, 2, 2, 'retirement');
        $this->authority->issueTransition('activate', 'activate', [2, 'certificate', 'admission', 'evidence']);
        self::assertSame('active', $this->installation()->activateSession('activate', 2, 'certificate', 'admission', 'evidence')->state);
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        $participants = $app->make(CollectionParticipants::class);
        $this->refuses(ProblemCode::OperationNotCancellable, fn () => $participants->closeRevoked(Provider::scope(), $ref));
        $this->retireComponent();
        self::assertSame(1, $app->make(CollectionRecoveryWorker::class)->run(Provider::scope(), 'remote-billium')['done']);
        $closure = $participants->closeRevoked(Provider::scope(), $ref);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure', "closure_ref=? AND proof_kind='not_admitted'", [$closure]));
        self::assertStringStartsWith('collection-owner-retired:', FixtureValues::text($this->value('SELECT evidence_ref FROM billium_collection_no_effect')));
        self::assertSame('active', $this->value('SELECT status FROM billium_collection_consent_guard'));
        self::assertSame(0, $this->rows('billium_execution_operation'));
        $this->openCollectionCase('obligation-a', 'case-next', 2);
        $this->refuses(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->authorizeCollection(Scenario::command('next', 'case-next')));
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-b', $this->collectionScope(), 'agreement-a', 'component-b', RecurringOwnerMode::BilliumScheduler, 'schedule-b',
        ));
        $app->make(Ledger::class)->recordObligation(new RecordObligation(
            'obligation-b', $this->account(), 'obligation-b', 'basis-b', 'customer-a', 'component-b', self::money(5000), self::digest(),
        ))->orThrow();
        $this->openCollectionCase('obligation-b', 'case-sibling');
        self::assertNotNull($this->dunningCaseService()->authorizeCollection(Scenario::command('sibling', 'case-sibling'))->stepAuthorizationRef);
        self::assertSame(0, $this->providerCalls());
    }

    public function test_failed_dispatch_cas_rolls_back_permit_before_component_cancellation(): void
    {
        $this->recordRecurringObligation();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $ref = FixtureValues::text($step->stepAuthorizationRef);
        $app = $this->application();
        $app->make(RootPaymentCommands::class)->admit(Provider::scope(), $this->paymentFromOutbox())->orThrow();
        $owner = $this->owner();
        $owner->unprepared("CREATE FUNCTION public.billium_test_cancel_dispatch_cas() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'");
        $owner->unprepared("CREATE TRIGGER billium_test_cancel_dispatch_cas BEFORE UPDATE ON billium_execution_attempt FOR EACH ROW WHEN (OLD.state='admitted' AND NEW.state='dispatching') EXECUTE FUNCTION public.billium_test_cancel_dispatch_cas()");
        try {
            $this->refuses(ProblemCode::RecoveryRequired, fn () => $this->dispatch('root-a'));
            self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
            self::assertNull($this->value('SELECT collection_dispatch_permit_ref FROM billium_execution_attempt'));
            self::assertSame('authorized', $this->value('SELECT status FROM billium_collection_step'));
        } finally {
            $owner->unprepared('DROP TRIGGER billium_test_cancel_dispatch_cas ON billium_execution_attempt');
            $owner->unprepared('DROP FUNCTION public.billium_test_cancel_dispatch_cas()');
        }
        $this->retireComponent();
        $closure = $app->make(CollectionParticipants::class)->closeRevoked(Provider::scope(), $ref);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure', "closure_ref=? AND proof_kind='not_dispatched'", [$closure]));
        self::assertSame(0, $this->providerCalls());
    }

    public function test_cancel_after_dispatch_permit_retains_actual_charge_and_requires_a_separate_refund(): void
    {
        $this->recordRecurringObligation();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $ref = FixtureValues::text($step->stepAuthorizationRef);
        $app = $this->application();
        $app->make(RootPaymentCommands::class)->admit(Provider::scope(), $this->paymentFromOutbox())->orThrow();
        Provider::host($app, $this->journal, cap: $this->capability, mode: ResponseMode::AcceptedThenTimeout, checkpoint: function (string $phase) use ($ref): void {
            if ($phase === 'before_accept') {
                self::assertSame(1, $this->rows('billium_collection_dispatch_permit'));
                $this->hostCancel($ref);
            }
        });
        $this->dispatch('root-a');
        $participants = $app->make(CollectionParticipants::class);
        self::assertSame('unknown', $this->value("SELECT state FROM billium_execution_attempt WHERE operation_ref='root-a'"));
        $this->refuses(ProblemCode::OperationNotCancellable, fn () => $participants->closeRevoked(Provider::scope(), $ref));
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertSame('dispatched', $this->value('SELECT status FROM billium_collection_step'));
        self::assertSame('open', $this->value('SELECT status FROM billium_settlement_intent'));
        self::assertSame(0, $this->rows('billium_payment'));
        Provider::host($app, $this->journal, cap: $this->capability);
        $this->owner()->update("UPDATE billium_execution_dispatch_work SET due_at=clock_timestamp() WHERE state='due'");
        $app->make(AttemptWorker::class)->recover(Provider::scope(), Refunds::attemptRef('root-a'), 'lookup',
            new DateTimeImmutable('+1 minute'))->orThrow();
        $this->deliverEvidence('root-a');
        self::assertSame(5000, $this->value('SELECT captured_minor FROM billium_payment'));
        self::assertSame(1, $this->providerCalls());
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.lookup'));
        self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund'"));
        self::assertSame(1, $this->rows('billium_collection_dispatch_permit'));

        $this->refund('cancel-compensation', 5000, 'collection');
        self::assertSame(5000, $this->value('SELECT captured_minor FROM billium_payment'));
        self::assertSame(5000, $this->value('SELECT refunded_minor FROM billium_payment'));
        self::assertSame(1, $this->rows('billium_execution_attempt', 'operation_ref=? AND collection_dispatch_permit_ref IS NULL', ['cancel-compensation']));
        self::assertSame(1, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame(2, $this->providerCalls());
    }

    public function test_annual_funding_replay_and_monthly_grant_retries_create_one_obligation_and_charge(): void
    {
        $price = $this->publishPrice('annual', 1, '50.00');
        $terms = $this->termsForPrice($price, new PeriodPolicy('Etc/UTC', 'year'), '2024-01-31T00:00:00');
        $this->applySchedule($terms);
        $occurrence = $this->ensure($terms, 'annual-first');
        $prepared = $this->prepareRating($terms, $occurrence, $price);
        $booking = $this->book($prepared, 'annual-book');
        $this->prepareCollection();
        $this->openCollectionCase(FixtureValues::text($booking->obligationRef));
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $payments = $this->application()->make(RootPaymentCommands::class);
        $payment = $this->paymentFromOutbox();
        $first = $payments->admit(Provider::scope(), $payment)->orThrow();
        $this->dispatch('root-a');
        $this->deliverEvidence('root-a');

        $this->applySchedule($terms, revision: 2);
        self::assertSame($occurrence, $this->ensure($terms, 'annual-reordered', revision: 2));
        self::assertSame($occurrence, $this->ensure($terms, 'annual-first'));
        self::assertSame($booking->obligationRef, $this->book($prepared, 'annual-book-replay')->obligationRef);
        self::assertSame($first->receiptId, $payments->admit(Provider::scope(), $payment)->orThrow()->receiptId);

        $host = new CollectionHostDouble;
        $grantTerms = Terms::make(['policy' => new PeriodPolicy('Etc/UTC', 'month')]);
        $resolver = $this->application()->make(IntervalResolver::class);
        $annual = $resolver->resolve($terms, 0);
        for ($month = 11; $month >= 0; $month--) {
            $period = $resolver->resolve($grantTerms, $month);
            self::assertTrue($annual->contains(new DateTimeImmutable($period->start)));
            self::assertLessThanOrEqual(new DateTimeImmutable($annual->end), new DateTimeImmutable($period->end));
            $host->grant($terms->componentRef, $period->start, $occurrence);
        }
        for ($month = 0; $month < 12; $month++) {
            $host->grant($terms->componentRef, $resolver->resolve($grantTerms, $month)->start, $occurrence);
        }
        self::assertCount(12, $host->grants);
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(1, $this->rows('billium_collection_authorization'));
        self::assertSame(1, $this->providerCalls());
    }

    public function test_bundle_component_proration_and_refund_keep_sibling_money_and_consumed_host_rights(): void
    {
        $fixture = self::arr(json_decode(FixtureValues::text(file_get_contents(
            dirname(__DIR__, 4).'/fixtures/vectors/collection/bundle-proration.fixture')), true, flags: JSON_THROW_ON_ERROR));
        $timezone = FixtureValues::text($fixture['timezone']);
        $calendar = new PeriodPolicy($timezone, 'month');
        $anchor = FixtureValues::text($fixture['anchorLocal']);
        $tax = FixtureValues::text($fixture['taxRate']);
        $oldPrice = $this->publishPrice('bundle-a', 1, FixtureValues::text($fixture['originalPrice']));
        $siblingPrice = $this->publishPrice('bundle-b', 1, FixtureValues::text($fixture['siblingPrice']));
        $a = $this->termsForPrice($oldPrice, $calendar, $anchor);
        $b = $this->termsForPrice($siblingPrice, $calendar, $anchor, 'component-b');
        $this->applySchedule($a);
        $this->applySchedule($b);
        $occurrenceA = $this->ensure($a, 'ensure-a');
        $occurrenceB = $this->ensure($b, 'ensure-b');
        $futureA = $this->ensure($a, 'future-a', ordinal: 1);
        $bookA = $this->book($this->prepareRating($a, $occurrenceA, $oldPrice, $tax), 'book-a');
        $bookB = $this->book($this->prepareRating($b, $occurrenceB, $siblingPrice, $tax), 'book-b');
        $obligationA = FixtureValues::text($bookA->obligationRef);
        $obligationB = FixtureValues::text($bookB->obligationRef);
        $grossA = self::integer($fixture['originalGrossMinor']);
        $grossB = self::integer($fixture['siblingGrossMinor']);
        $refundMinor = self::integer($fixture['refundMinor']);
        $app = $this->application();
        $ledger = $app->make(Ledger::class);
        $ledger->createInvoice(new CreateInvoice('draft', $this->account(), 'bundle', 'customer-a', [
            new InvoiceLineInput($obligationA, 0), new InvoiceLineInput($obligationB, 1),
        ]))->orThrow();
        $ledger->issueInvoice(new IssueInvoice('issue', $this->account(), 'bundle'))->orThrow();
        $ledger->declareSettlementIntent(new DeclareSettlementIntent('bundle-funding', $this->account(), 'intent-a', 'RUB',
            [$obligationA, $obligationB]))->orThrow();
        $payments = $app->make(RootPaymentCommands::class);
        $charge = Provider::wire(['money' => self::money($grossA + $grossB)->toWire()]);
        $paid = $payments->admit(Provider::scope(), $charge)->orThrow();
        $this->dispatch('root-a');
        $this->deliverEvidence('root-a');

        $host = new CollectionHostDouble;
        self::assertNull($host->provision('component-a', 'timeout'));
        self::assertSame($paid->receiptId, $payments->admit(Provider::scope(), $charge)->orThrow()->receiptId);
        $grant = $host->provision('component-a', 'active');
        self::assertNotNull($grant);
        self::assertSame($grant, $host->provision('component-a', 'active'));
        $host->consumed[$grant] = 7;
        $sibling = $host->provision('component-b', 'active');
        self::assertNotNull($sibling);
        $siblingMoney = $this->value('SELECT row_to_json(o)::text FROM billium_obligation o WHERE obligation_ref=?', [$obligationB]);
        $invoiceTermsSql = 'SELECT jsonb_agg(to_jsonb(l) ORDER BY position)::text FROM billium_invoice_line l';
        $invoiceTerms = $this->value($invoiceTermsSql);

        $newPrice = $this->publishPrice('bundle-a', 2, FixtureValues::text($fixture['replacementPrice']));
        $futureCalendar = new PeriodPolicy(FixtureValues::text($fixture['replacementTimezone']), 'month');
        $futureTerms = $this->termsForPrice($newPrice, $futureCalendar, $anchor, agreementRevision: 2);
        $this->applySchedule($futureTerms, 2, new DateTimeImmutable('2026-11-02Z'));
        $this->cancelSchedule($a->componentRef, 2);
        $this->refuses(ProblemCode::ValidationInvalid, fn () => $this->ensure($futureTerms, 'fresh-after-cancel', revision: 2, ordinal: 2));
        self::assertSame($occurrenceA, $this->ensure($a, 'ensure-a'));
        self::assertSame('obligated', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$occurrenceA]));
        self::assertSame('cancelled', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$futureA]));

        $this->bindOriginalTaxCorrection($tax);
        $rating = $app->make(RatingCorrection::class);
        $prepared = $rating->prepare(new PrepareCorrection('seller-a', 'test', $occurrenceA, CorrectionKind::PartialCancel,
            new HostAmendment('bundle-cancel-a', 1, new DateTimeImmutable(FixtureValues::text($fixture['effectiveAt'])),
                ProrationPolicyId::from(FixtureValues::text($fixture['prorationPolicy'])), 'partial_cancel', $a->policy->timezone),
            'partial_cancel', ReleasePolicy::ReverseAllocations));
        self::assertInstanceOf(PreparedCorrection::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame($oldPrice->termsDigest, $prepared->inputs->prices[0]->termsDigest);
        self::assertSame($fixture['elapsedDays'], $prepared->inputs->proration['elapsed'] ?? null);
        self::assertSame($fixture['totalDays'], $prepared->inputs->proration['total'] ?? null);
        self::assertSame([$fixture['deltaNetMinor'], $fixture['deltaTaxMinor'], (string) -$refundMinor],
            [$prepared->computation->deltaNetMinor, $prepared->computation->deltaTaxMinor, $prepared->computation->deltaGrossMinor]);
        $correction = $rating->apply(new ApplyCorrection($prepared, 'partial-a'));
        self::assertSame('credit_note', $correction->outcome);
        self::assertSame($correction->creditNoteRef, $rating->apply(new ApplyCorrection($prepared, 'partial-a-replay'))->creditNoteRef);
        self::assertSame(1, $this->rows('billium_credit_note', 'amount_minor=?', [$refundMinor]));
        self::assertSame(1, $this->providerCalls());
        $this->refund('bundle-a-refund', $refundMinor);
        self::assertSame(2, $this->providerCalls());
        self::assertSame($refundMinor, $this->value('SELECT refunded_minor FROM billium_payment'));
        self::assertSame($grossA + $grossB - $refundMinor, $this->value('SELECT allocated_minor FROM billium_payment'));
        self::assertSame($siblingMoney, $this->value('SELECT row_to_json(o)::text FROM billium_obligation o WHERE obligation_ref=?', [$obligationB]));
        self::assertSame($invoiceTerms, $this->value($invoiceTermsSql));
        self::assertSame(7, $host->consumed[$grant]);
        self::assertSame($sibling, $host->provision('component-b', 'active'));
        self::assertSame('active', $this->value("SELECT state FROM billium_schedule_billing_schedule WHERE component_ref='component-b'"));
    }

    private function recordRecurringObligation(): void
    {
        $this->prepareCollection();
        $this->application()->make(Ledger::class)->recordObligation(new RecordObligation(
            'obligation-a', $this->account(), 'obligation-a', 'basis-a', 'customer-a', 'component-a', self::money(5000), self::digest(),
        ))->orThrow();
        $this->openCollectionCase('obligation-a');
    }

    private function prepareCollection(): void
    {
        $app = $this->application();
        $app->instance(CollectionConsentGuard::class, new Scenario);
        $this->consentService()->registerBinding($this->registerCommand(
            merchantAccountRef: 'account-a', merchantConfigRevision: 'merchant-a', currencies: ['RUB'],
        ), $app->make(MerchantRegistry::class)->forAdmission(Provider::scope(), 'merchant-a'), $this->capability);
        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            'policy', $this->collectionScope(), 'policy-a', 1, [CollectionChannel::SavedMethod],
            [new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 0)], 1, 3, [DeclineCategory::SoftDecline], 86400,
        ));
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-a', $this->collectionScope(), 'agreement-a', 'component-a', RecurringOwnerMode::BilliumScheduler, 'schedule-a',
        ));
    }

    private function openCollectionCase(string $obligation, string $case = 'case-a', int $generation = 1): void
    {
        $this->dunningCaseService()->openCase(new OpenDunningCase('open-'.$case, $this->collectionScope(), $case, 'account-a',
            $obligation, $generation, 'policy-a', 1, 'RUB', 5000, openedAt: new DateTimeImmutable('-1 hour')));
    }

    private function hostCancel(string $authorizationRef, string $reason = 'component cancelled'): RevocationReceipt
    {
        return $this->consentService()->revokeOrCancel(new RevokeConsentCommand(
            'host-cancel', $this->collectionScope(), 'consent-1', 'cancel-a', 2, 1, $reason, self::digest(),
            TombstoneKind::HostCancelled, $authorizationRef, 'intent-a',
        ));
    }

    private function retireComponent(): void
    {
        $this->dunningPolicyService()->retireRecurringOwner(new RetireRecurringOwner(
            'retire-a', $this->collectionScope(), 'component-a', 1, 'host component cancelled',
        ));
    }

    private function termsForPrice(PriceVersion $price, PeriodPolicy $policy, string $anchor, string $component = 'component-a',
        int $agreementRevision = 1): ScheduleRevisionTerms
    {
        return Terms::make(['purpose' => 'recurring_charge', 'componentRef' => $component, 'anchorLocal' => $anchor,
            'policy' => $policy, 'priceVersionRef' => $price->priceRef.'@'.$price->version, 'monetaryTermsDigest' => $price->termsDigest,
            'agreementRevision' => $agreementRevision, 'externalCorrelationRef' => 'host:'.$component,
        ]);
    }

    private function applySchedule(ScheduleRevisionTerms $terms, int $revision = 1, ?DateTimeImmutable $effective = null): void
    {
        $this->application()->make(OccurrenceService::class)->applyScheduleRevision(new ApplyScheduleRevision(
            'seller-a', 'test', 'schedule-'.$terms->componentRef, $revision - 1, $revision - 1, $revision, $terms,
            $effective ?? new DateTimeImmutable('2024-01-01Z'), self::digest(), $terms->componentRef.'-rev-'.$revision,
        ));
    }

    private function ensure(ScheduleRevisionTerms $terms, string $key, int $revision = 1, int $ordinal = 0): string
    {
        $period = $this->application()->make(IntervalResolver::class)->resolve($terms, $ordinal);
        $receipt = $this->application()->make(OccurrenceService::class)->ensureOccurrence(new EnsureOccurrence(
            'seller-a', 'test', $terms->componentRef, OccurrencePurpose::RecurringCharge, new DateTimeImmutable($period->start),
            new DateTimeImmutable($period->end), 'schedule-'.$terms->componentRef, $revision, self::digest(), commandKey: $key, ordinal: $ordinal,
        ));

        return FixtureValues::text($receipt->body()['occurrenceRef']);
    }

    private function cancelSchedule(string $component, int $revision): void
    {
        $service = $this->application()->make(OccurrenceService::class);
        $command = new CancelSchedule('seller-a', 'test', 'schedule-'.$component, $revision, $revision, 0, 'cancel-'.$component);
        $receipt = $service->cancelSchedule($command)->body();
        self::assertSame(1, $receipt['cancelGeneration']);
        self::assertSame($receipt, $service->cancelSchedule($command)->body());
    }

    private function publishPrice(string $ref, int $version, string $amount): PriceVersion
    {
        $draft = new PriceVersion('seller-a', $ref, $version, StrategyId::Fixed, 1, 'RUB', 2, 'ISO4217-2026-10', 'period',
            new QuantityRules(9, '1', '1', '1', IncrementMode::None), new FixedTerms($amount, 'per_component_occurrence'),
            RoundingMode::HalfUp, RoundingStage::AfterTax, 'fixture/1', null, 'draft');
        $price = FixtureArguments::construct(PriceVersion::class, [...get_object_vars($draft), 'termsDigest' => CanonicalJson::termsDigest($draft)]);
        $this->application()->make(PriceVersionStore::class)->publish($price, $ref.'-'.$version);

        return $price;
    }

    private function prepareRating(ScheduleRevisionTerms $terms, string $occurrence, PriceVersion $price, string $rate = '0'): PreparedRating
    {
        $app = $this->application();
        $port = new TestTaxPort;
        $port->rate = $rate;
        $app->instance(TaxSnapshotPort::class, $port);
        $prepared = $app->make(RatingPreparer::class)->prepare(new PrepareRating($occurrence, 'account-a', '{"customerRef":"customer-a"}',
            '1', self::digest(), self::digest(), [['componentRef' => $terms->componentRef, 'snapshotRef' => 'fixed',
                'priceRef' => $price->priceRef, 'priceVersion' => $price->version]]));
        self::assertInstanceOf(PreparedRating::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));

        return $prepared;
    }

    private function book(PreparedRating $prepared, string $key): BookingReceipt
    {
        return $this->application()->make(RatingBooking::class)->book(new BookRatedBasis($prepared, $key));
    }

    private function bindOriginalTaxCorrection(string $rate): void
    {
        $port = new readonly class($rate) implements TaxSnapshotPort
        {
            public function __construct(private string $rate) {}

            public function resolve(TaxResolutionRequest $request): TaxSnapshot
            {
                throw new \LogicException('Expected correction policy lookup');
            }

            public function resolveCorrection(TaxCorrectionRequest $request): TaxCorrectionPolicySnapshot
            {
                $from = new DateTimeImmutable('2026-01-01Z');
                $tax = new TaxSnapshot('bundle-original-rate', TaxSnapshotKind::Correction, TaxMode::Exclusive,
                    'TEST', 'test-tax', $this->rate, $from, $request->originalTaxPoint, null, 'host', '1', $request->inputDigest, 'evidence');

                return new TaxCorrectionPolicySnapshot('bundle-policy', '1', 'original_rate', $tax, $from, null,
                    $request->inputDigest, 'policy-output', '1');
            }
        };
        $date = new DateTimeImmutable('2026-10-16Z');
        $request = new TaxCorrectionRequest('seller-a', 'test', 'fixture', 'original', 'digest', $date, $date, 'partial_cancel',
            'TEST', '3100', '1500', 'input');
        $this->application()->make(TaxCorrectionPolicyFrontier::class)->publish($port->resolveCorrection($request), 'tax-policy');
        $this->application()->instance(TaxSnapshotPort::class, $port);
    }

    private function refund(string $ref, int $minor, string $source = 'checkout'): void
    {
        $changes = $source === 'checkout' ? [] : ['source' => ['context' => $source, 'intentRef' => $ref]];
        $wire = Refunds::refund($ref, $minor, 2, $changes);
        $refunds = $this->application()->make(RefundCommands::class);
        $first = $refunds->admit(Provider::scope(), $wire)->orThrow();
        $this->dispatch($ref);
        $this->deliverEvidence($ref, 2);
        self::assertSame($first->receiptId, $refunds->admit(Provider::scope(), $wire)->orThrow()->receiptId);
        $records = new DurableJournal($this->journal)->records();
        $charge = array_find($records, static fn (object $e): bool => $e instanceof ProviderRecord && $e->kind === EffectKind::Charge);
        $refund = array_find($records, static fn (object $e): bool => $e instanceof ProviderRecord && $e->kind === EffectKind::Refund);
        self::assertInstanceOf(ProviderRecord::class, $charge);
        self::assertInstanceOf(ProviderRecord::class, $refund);
        self::assertSame($minor, $refund->effectAmounts['refunded']);
        self::assertSame($charge->request->binding->merchantRevisionRef, $refund->request->binding->merchantRevisionRef);
        self::assertSame($charge->request->binding->rootOperationRef, $refund->request->binding->rootOperationRef);
        self::assertSame($charge->request->binding->collectionAuthorizationRef, $refund->request->binding->collectionAuthorizationRef);
    }

    private function paymentFromOutbox(): ExecutePayment
    {
        $payment = (new Codec)->decode(FixtureValues::text($this->value("SELECT payload->'payment' FROM billium_outbox_command WHERE command_type='collection.execute.v1'")));
        self::assertInstanceOf(ExecutePayment::class, $payment);

        return $payment;
    }

    private function dispatch(string $operation): void
    {
        $this->application()->make(AttemptWorker::class)->dispatch(Provider::scope(), Refunds::attemptRef($operation),
            'sender', new DateTimeImmutable('+1 minute'))->orThrow();
    }

    private function deliverEvidence(string $operation, int $count = 1): void
    {
        $app = $this->application();
        $work = FixtureValues::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$operation]));
        $reduced = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertCount($count, $reduced->observationCommandRefs);
        foreach ($reduced->observationCommandRefs as $ref) {
            $fact = OutcomeDeliveryTest::attest($app, $this->journal, $ref);
            $payload = ObservationFactory::json($fact->raw);
            $applier = $app->make(ObservationApplier::class);
            $applier->apply($payload);
            $applier->apply($payload);
        }
    }

    private function providerCalls(): int
    {
        return UnknownResultTest::events($this->journal, 'provider.call');
    }

    private function account(): BillingAccountScope
    {
        return new BillingAccountScope('seller-a', 'test', 'account-a');
    }

    private static function money(int $minor): Money
    {
        return new Money($minor, Currency::of('RUB'));
    }

    private function refuses(ProblemCode $expected, callable $action): void
    {
        try {
            $action();
            self::fail('Expected '.$expected->value);
        } catch (ProblemException $exception) {
            self::assertSame($expected, $exception->problem, $exception->detail);
        }
    }
}

/** In-memory host receipts for REQ-NBR-06 and COV-AC-373. */
final class CollectionHostDouble
{
    /** @var array<string, string> */
    public array $grants = [];

    /** @var array<string, int> */
    public array $consumed = [];

    public function grant(string $component, string $start, string $funding): string
    {
        return $this->grants[$component.':'.$start] ??= $funding.':'.$start;
    }

    public function provision(string $component, string $status): ?string
    {
        return $status === 'active' ? $this->grant($component, 'initial', $component) : null;
    }
}
