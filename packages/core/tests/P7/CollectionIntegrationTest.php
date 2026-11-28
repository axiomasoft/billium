<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOutcomeSignal;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionSignalKind;
use Billium\Collection\Enums\CustomerActionStatus;
use Billium\Collection\Enums\CustomerActionType;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Internal\Work\CollectionRecoveryWorker;
use Billium\Collection\Internal\Work\CollectionWork;
use Billium\Collection\Internal\Work\DueCollectionWorker;
use Billium\Collection\Laravel\CollectionServiceProvider;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\Drill\ExecutionRestoreTest as Restore;
use Billium\Core\Tests\P7\Support\CollectionAdmissionScenario as Scenario;
use Billium\Core\Tests\P7\Support\ExecutableCollectionTestCase;
use Billium\Core\Tests\P7\Support\RecurringUsageFixture;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Provider;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\AllocationReceipt;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Obligations\AmendObligationDuePolicy;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
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
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fiscal\SimulatedFiscalDriver;
use Billium\Testkit\Fiscal\SimulatedFiscalDrivers;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Restore\PostgresSnapshot;
use Billium\Usage\Laravel\UsageServiceProvider;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('P7.7')]
final class CollectionIntegrationTest extends ExecutableCollectionTestCase
{
    private const string PROFILE = 'remote-billium';

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RatingServiceProvider::class, ScheduleServiceProvider::class, UsageServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection', 'rating', 'schedule', 'usage']);
        foreach (['rating', 'schedule', 'usage'] as $module) {
            $config->set('billium.'.$module.'.enabled', true);
        }
    }

    // The renewal fixture books its own debt through RatingBooking.
    protected function seedObligation(): void {}

    public function test_fixed_and_metered_renewal_replays_collect_once_and_fiscal_outage_recovers_independently(): void
    {
        $app = $this->application();
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'pilot', [self::PROFILE, 'tariffler'], ['source-a']));
        $fact = RecurringUsageFixture::fact();
        $snapshotRef = RecurringUsageFixture::freeze($app, $this->owner(), $fact);
        $tax = new TestTaxPort;
        $tax->rate = '0';
        $app->instance(TaxSnapshotPort::class, $tax);
        $fixed = $this->publishPrice('pilot-fixed', StrategyId::Fixed, new FixedTerms('30.00', 'per_component_occurrence'));
        $this->publishPrice('pilot-usage', StrategyId::PerUnit, new PerUnitTerms('2.00'));

        $terms = Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2026-10-01T00:00:00',
            'priceVersionRef' => 'pilot-fixed@1', 'monetaryTermsDigest' => $fixed->termsDigest]);
        $schedule = $app->make(OccurrenceService::class);
        $start = new DateTimeImmutable(RecurringUsageFixture::START);
        $end = new DateTimeImmutable(RecurringUsageFixture::END);
        $schedule->applyScheduleRevision(new ApplyScheduleRevision(
            'seller-a', 'test', 'schedule-a', 0, 0, 1, $terms, $start, self::digest(), 'renewal-revision-1',
        ));
        $ensure = static fn (int $revision, string $key): EnsureOccurrence => new EnsureOccurrence(
            'seller-a', 'test', 'component-a', OccurrencePurpose::RecurringCharge, $start, $end,
            'schedule-a', $revision, self::digest(), commandKey: $key, ordinal: 0,
        );
        $firstOccurrence = $schedule->ensureOccurrence($ensure(1, 'renewal-1'))->body();
        $occurrence = FixtureValues::text($firstOccurrence['occurrenceRef']);
        $prepared = $app->make(RatingPreparer::class)->prepare(new PrepareRating(
            $occurrence, 'account-a', CanonicalJson::encode($fact->attribution), '1', self::digest(), self::digest(), [
                ['componentRef' => 'component-a', 'snapshotRef' => 'fixed', 'priceRef' => 'pilot-fixed', 'priceVersion' => 1],
                ['componentRef' => 'component-usage', 'snapshotRef' => $snapshotRef, 'priceRef' => 'pilot-usage', 'priceVersion' => 1],
            ],
        ));
        self::assertInstanceOf(PreparedRating::class, $prepared, json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame(['3000', '2000'], array_column($prepared->draft->lines, 'grossMinor'));

        $booker = $app->make(RatingBooking::class);
        $book = new BookRatedBasis($prepared, 'renewal-book');
        $booking = $booker->book($book);
        self::assertSame('5000', $booking->grossMinor);
        $obligation = FixtureValues::text($booking->obligationRef);
        $this->registerRecurringOwner();
        $case = new OpenDunningCase('open-renewal', $this->collectionScope(), 'case-a', 'account-a',
            $obligation, 1, 'policy-a', 1, 'RUB', 5000, componentRef: 'component-a');
        $this->dunningCaseService()->openCase($case);
        $duplicates = ConcurrencyStand::run(Scenario::class, 2, ['replaySameCommand' => true]);
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        foreach ($duplicates as $duplicate) {
            self::assertSame(['accepted' => true, 'id' => $step->id], self::ok($duplicate));
        }
        $payment = $this->commandFromOutbox();
        $payments = $app->make(RootPaymentCommands::class);
        $admission = $payments->admit(Provider::scope(), $payment)->orThrow();
        self::assertSame('root-a', $app->make(ExecutionQueries::class)
            ->receipt(Provider::scope(), FixtureValues::text($admission->body['receiptRef']))->operationRef);
        $this->runWorker();
        $allocation = $this->deliverOutcome('root-a');
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['done']);

        $schedule->applyScheduleRevision(new ApplyScheduleRevision(
            'seller-a', 'test', 'schedule-a', 1, 1, 2, $terms, $start, self::digest(), 'renewal-revision-2',
        ));
        self::assertSame($occurrence, $schedule->ensureOccurrence($ensure(2, 'renewal-duplicate'))->body()['occurrenceRef']);
        self::assertSame($firstOccurrence, $schedule->ensureOccurrence($ensure(1, 'renewal-1'))->body());
        self::assertSame($booking->body(), $booker->book($book)->body());
        self::assertSame($obligation, $booker->book(new BookRatedBasis($prepared, 'duplicate-booking'))->obligationRef);
        self::assertSame($step->stepAuthorizationRef, $this->dunningCaseService()->authorizeCollection(Scenario::command())->stepAuthorizationRef);
        self::assertSame($admission->receiptId, $payments->admit(Provider::scope(), $payment)->orThrow()->receiptId);
        self::assertSame(0, $this->runWorker()['done']);

        $this->assertFiscalRecovery($allocation, $obligation);
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(1, $this->rows('billium_collection_authorization'));
        self::assertSame(1, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame('settled', $this->value('SELECT status FROM billium_collection_dunning_case'));
        $this->assertBalance($obligation, 5000, 5000, 0);
        $this->assertCharges([5000], 1);
    }

    public function test_applied_decline_and_manual_partial_payment_collect_only_the_remainder(): void
    {
        parent::seedObligation();
        $app = $this->application();
        $this->registerRecurringOwner();
        $now = new DateTimeImmutable;
        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            'retry-policy', $this->collectionScope(), 'retry-policy', 1, [CollectionChannel::SavedMethod],
            [new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 0), new DunningPolicyStepDefinition(2, CollectionChannel::SavedMethod, 0)],
            2, 2, [DeclineCategory::SoftDecline], 86400,
        ));
        $this->dunningCaseService()->openCase(new OpenDunningCase('open-retry', $this->collectionScope(), 'case-retry', 'account-a',
            'obligation-a', 2, 'retry-policy', 1, 'RUB', 5000, openedAt: $now->modify('-1 minute')));
        $ledger = $app->make(Ledger::class);
        $ledger->amendObligationDuePolicy(new AmendObligationDuePolicy('set-due', $this->account(), 'obligation-a',
            new ObligationDuePolicy($now->modify('-1 minute'), 'policy.v1', 'host-due-policy', self::digest())))->orThrow();
        $first = $this->dunningCaseService()->authorizeCollection(Scenario::command('decline', 'case-retry'));
        self::assertSame([
            'collection_oldest_due_age_seconds' => 0, 'collection_oldest_unknown_age_seconds' => 0,
            'collection_classified_declines' => 0, 'collection_transport_retries' => 0,
        ], $this->metrics(new DateTimeImmutable('-1 minute')));
        self::assertGreaterThanOrEqual(60, $this->metrics(new DateTimeImmutable('+1 minute'))['collection_oldest_due_age_seconds']);
        self::assertSame([0, 0, 0, 0], array_values($this->metrics(new DateTimeImmutable('+1 minute'), 'other-seller')));
        // Lose a claim before dispatch. It consumes transport budget only.
        self::assertCount(1, $app->make(CollectionWork::class)->claim($this->financialScope(), 1, 30)['claims']);
        $this->makeReady();
        Provider::host($app, $this->journal, cap: $this->capability, mode: ResponseMode::Declined);
        $this->runWorker();
        $this->assertCharges([], 1);
        $this->deliverOutcome($first->operationRef);
        $this->makeReady();
        $worker = $app->make(CollectionRecoveryWorker::class);
        self::assertSame(1, $this->runWorker()['parked']);
        self::assertSame(0, $this->metrics()['collection_classified_declines']);
        self::assertTrue($worker->reconcileParked(Provider::scope(), self::PROFILE, $first->stepRef,
            static fn (): DeclineCategory => DeclineCategory::SoftDecline));
        self::assertSame(1, $this->metrics()['collection_classified_declines']);
        self::assertSame(1, $this->metrics()['collection_transport_retries']);
        $this->assertBalance('obligation-a', 5000, 0, 5000);

        $credit = new RecordCustomerCredit('cash-record', $this->account(), 'cash-credit', 'cash-receipt', $this->money(1500));
        $allocate = new Allocate('cash-allocate', $this->account(), 'manual-allocation', 'obligation-a', $this->money(1500),
            customerCreditRef: 'cash-credit');
        $cashReceipt = $ledger->recordCustomerCredit($credit)->orThrow();
        $manualReceipt = $ledger->allocate($allocate)->orThrow();
        self::assertNotEmpty($cashReceipt->receiptId);
        self::assertNotEmpty($manualReceipt->receiptId);
        self::assertSame($cashReceipt->receiptId, $ledger->recordCustomerCredit($credit)->orThrow()->receiptId);
        self::assertSame($manualReceipt->receiptId, $ledger->allocate($allocate)->orThrow()->receiptId);
        $this->assertBalance('obligation-a', 5000, 1500, 3500);

        $queue = $app->make(DueCollectionWorker::class);
        $next = $queue->schedule(Provider::scope(), self::PROFILE, 1, 'consent-1', 1, 2, new DateTimeImmutable)['authorized'];
        self::assertCount(1, $next);
        self::assertSame(2, $next[0]->stepNo);
        self::assertSame(3500, $next[0]->amountMinor);
        self::assertNotSame($first->operationRef, $next[0]->operationRef);
        self::assertNotSame($first->businessPaymentIntentRef, $next[0]->businessPaymentIntentRef);
        self::assertSame([], $queue->schedule(Provider::scope(), self::PROFILE, 1, 'consent-1', 1, 2, new DateTimeImmutable)['authorized']);
        Provider::host($app, $this->journal, cap: $this->capability);
        $this->runWorker();
        $this->deliverOutcome($next[0]->operationRef);
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['done']);
        self::assertSame(2, $this->rows('billium_allocation'));
        self::assertSame(3500, $this->value('SELECT SUM(captured_minor)::bigint FROM billium_payment'));
        self::assertSame('settled', $this->value("SELECT status FROM billium_collection_dunning_case WHERE case_ref = 'case-retry'"));
        $this->assertBalance('obligation-a', 5000, 5000, 0);
        $this->assertCharges([3500], 2);
    }

    public function test_revoked_consent_closes_admitted_work_with_no_effect_receipt_and_no_charge(): void
    {
        parent::seedObligation();
        $this->registerRecurringOwner();
        $app = $this->application();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $payments = $app->make(RootPaymentCommands::class);
        $payment = $this->commandFromOutbox();
        $admission = $payments->admit(Provider::scope(), $payment)->orThrow();
        $revoke = new RevokeConsentCommand(
            'revoke', $this->collectionScope(), 'consent-1', 'tombstone-a', 2, 0, 'customer revoked mandate', self::digest(),
            TombstoneKind::ConsentRevoked, $step->stepAuthorizationRef, $step->businessPaymentIntentRef,
        );
        $tombstone = $this->consentService()->revokeOrCancel($revoke);
        self::assertEquals($tombstone, $this->consentService()->revokeOrCancel($revoke));
        self::assertSame(1, $this->runWorker()['done']);
        self::assertSame($admission->receiptId, $payments->admit(Provider::scope(), $payment)->receiptId);
        $closure = $this->row('SELECT closure_ref, ledger_receipt_ref, proof_kind FROM billium_collection_no_effect');
        self::assertSame('not_dispatched', $closure['proof_kind']);
        self::assertNotEmpty($closure['closure_ref']);
        $ledgerReceipt = $app->make(LedgerQueries::class)->receipt(FixtureValues::text($closure['ledger_receipt_ref']));
        self::assertSame($closure['closure_ref'], $ledgerReceipt['closure_ref']);
        self::assertSame($step->businessPaymentIntentRef, $ledgerReceipt['business_intent_ref']);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));
        $this->assertBalance('obligation-a', 5000, 0, 5000);
        $this->assertCharges([], 0);
    }

    public function test_restored_pre_dispatch_snapshot_cannot_collect_an_already_accepted_unknown_payment(): void
    {
        parent::seedObligation();
        $this->registerRecurringOwner();
        $app = $this->application();
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $payment = $this->commandFromOutbox();
        $source = TestEnvironment::databaseName();
        $dump = $this->journal.'.dump';
        $snapshot = null;
        try {
            $snapshot = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $dump);
            Provider::host($app, $this->journal, cap: $this->capability, mode: ResponseMode::AcceptedThenTimeout);
            self::assertSame(1, $this->runWorker()['due']);
            self::assertSame('parked_unknown', $this->value('SELECT status FROM billium_collection_dunning_case'));
            $this->assertBalance('obligation-a', 5000, 0, 5000);
            $this->assertCharges([5000], 1);
            self::assertGreaterThanOrEqual(60, $this->metrics(new DateTimeImmutable('+1 minute'))['collection_oldest_unknown_age_seconds']);

            $this->makeReady();
            $this->runWorker();
            self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.lookup'));
            $this->deliverOutcome('root-a');
            $this->makeReady();
            self::assertSame(1, $this->runWorker()['done']);
            $this->assertBalance('obligation-a', 5000, 5000, 0);
            self::assertSame(0, $this->metrics()['collection_oldest_unknown_age_seconds']);
            $this->authority->issueTransition('seal', 'pilot-seal', [1]);
            $this->installation()->seal('pilot-seal', 1);

            $cloneName = $snapshot->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);
            $clone = Restore::cloneApplication($app);
            $clone->make(Repository::class)->set('billium.collection.enabled', true);
            $clone->register(CollectionServiceProvider::class);
            $clone->instance(CollectionConsentGuard::class, new Scenario);
            $db = $clone->make(DatabaseManager::class)->connection();
            self::assertSame(1, $db->table('billium_collection_authorization')->count());
            self::assertSame(0, $db->table('billium_collection_dispatch_permit')->count());
            self::assertSame(0, $db->table('billium_payment')->count());
            $token = FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2);
            $clone->make(FinancialSessionAdmission::class)->set($token);
            $clone->make(SimulatedFinancialAuthorityEvidence::class)->issueProcess($token);
            $this->assertRefused(ProblemCode::RecoveryRequired, fn () => $clone->make(CollectionRecoveryWorker::class)
                ->run(Provider::scope(), self::PROFILE));
            $this->assertRefused(ProblemCode::RecoveryRequired, fn () => $clone->make(RootPaymentCommands::class)
                ->admit(Provider::scope(), $payment)->orThrow());
            self::assertSame(0, $db->table('billium_execution_operation')->count());
            self::assertSame(0, $db->table('billium_allocation')->count());
            $this->assertCharges([5000], 1);
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            $snapshot?->close();
            if (is_file($dump)) {
                unlink($dump);
            }
        }
    }

    public function test_customer_action_receipt_for_pending_payment_cannot_settle_or_retry_the_charge(): void
    {
        parent::seedObligation();
        $this->registerRecurringOwner();
        Provider::host($this->application(), $this->journal, cap: $this->capability, mode: ResponseMode::Pending);
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $this->runWorker();
        self::assertSame('pending', $this->value('SELECT state FROM billium_execution_attempt'));
        $now = new DateTimeImmutable;
        $action = new RecordCustomerAction(
            'record-action', $this->collectionScope(), 'action-a', 'account-a', 'consent-1', 1,
            $step->businessPaymentIntentRef, CustomerActionType::ThreeDsChallenge, 'challenge-a',
            ['method' => '3ds2_challenge'], $now->modify('+10 minutes'), $step->operationRef,
        );
        $receipt = $this->consentService()->recordCustomerAction($action);
        self::assertSame(CustomerActionStatus::RequiresAction, $receipt->status);
        self::assertFalse($receipt->paid);
        self::assertFalse($receipt->terminalFailure);
        self::assertEquals($receipt, $this->consentService()->recordCustomerAction($action));
        self::assertEquals($receipt, $this->consentQueries()->findCustomerAction($this->collectionScope(), $receipt->actionRef));

        $signals = $this->signalService();
        $signal = new RecordOutcomeSignal('signal-action', $this->collectionScope(), 'case-a',
            CollectionSignalKind::CustomerActionRequired, 'action-a', $now, ['actionRef' => $receipt->actionRef]);
        $result = $signals->recordOutcomeSignal($signal);
        self::assertTrue($result->signalOnly);
        self::assertSame($result->signalRef, $signals->recordOutcomeSignal($signal)->signalRef);
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $signals->recordOutcomeSignal(new RecordOutcomeSignal(
            'signal-success', $this->collectionScope(), 'case-a', CollectionSignalKind::RenewalSucceeded, 'paid', $now,
        )));
        $this->makeReady();
        $this->runWorker();
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.lookup'));
        self::assertSame(1, $this->rows('billium_collection_step'));
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));
        $this->assertBalance('obligation-a', 5000, 0, 5000);
        $this->assertCharges([], 1);
    }

    private function registerRecurringOwner(): void
    {
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-1', $this->collectionScope(), 'agreement-a', 'component-a', RecurringOwnerMode::BilliumScheduler, 'schedule-a',
        ));
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-2', $this->collectionScope(), 'agreement-a', 'component-a', RecurringOwnerMode::ProviderAutonomousSubscription, 'provider-schedule',
        )));
        self::assertSame(1, $this->rows('billium_collection_recurring_owner'));
    }

    private function publishPrice(string $ref, StrategyId $strategy, FixedTerms|PerUnitTerms $terms): PriceVersion
    {
        $draft = new PriceVersion('seller-a', $ref, 1, $strategy, 1, 'RUB', 2, 'ISO4217-2026-10', 'call',
            new QuantityRules(9, '1', '1', '1', IncrementMode::None), $terms,
            RoundingMode::HalfUp, RoundingStage::AfterTax, 'pilot/1', null, 'draft');
        $price = FixtureArguments::construct(PriceVersion::class, [...get_object_vars($draft), 'termsDigest' => CanonicalJson::termsDigest($draft)]);
        $this->application()->make(PriceVersionStore::class)->publish($price, $ref);

        return $price;
    }

    private function deliverOutcome(string $operation): AllocationReceipt
    {
        $app = $this->application();
        $work = FixtureValues::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint = ? AND state = 'due'", [$operation]));
        $outcome = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertCount(1, $outcome->observationCommandRefs);
        $fact = OutcomeDeliveryTest::attest($app, $this->journal, $outcome->observationCommandRefs[0]);
        $payload = ObservationFactory::json($fact->raw);
        $applier = $app->make(ObservationApplier::class);
        $receipt = $applier->apply($payload);
        $replay = $applier->apply($payload);
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->receiptRef, $replay->receiptRef);
        self::assertSame($receipt->body, $replay->body);

        return $receipt;
    }

    private function assertFiscalRecovery(AllocationReceipt $allocation, string $obligation): void
    {
        $app = $this->application();
        $scope = Provider::scope();
        $driver = new SimulatedFiscalDriver(supportedRoutes: [FiscalRoute::FiscalDriver],
            txLevelProbe: static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel());
        $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
        $fiscal = $app->make(FiscalOrchestrator::class);
        $fiscal->configurePolicy(new FiscalPolicyConfig($scope, self::PROFILE, FiscalOperationKind::Charge, true,
            FiscalRoute::FiscalDriver, $driver->driverId(), $driver->contractVersion()));
        $intents = $fiscal->syncActualEffectsForRoot($scope, 'root-a', self::PROFILE);
        self::assertCount(1, $intents);
        $intent = $intents[0];
        self::assertSame(5000, $intent->amount->minor);
        $driver->loseRegisterResponseFor($intent->externalId);
        $now = new DateTimeImmutable;
        self::assertSame(FiscalIntentState::Unknown, $fiscal->dispatchIntent($scope, $intent->intentRef, $now)->state);
        $driver->setOffline(true);
        $stillUnknown = $fiscal->lookupIntent($scope, $intent->intentRef, $now->modify('+24 hours'));
        self::assertSame(FiscalIntentState::Unknown, $stillUnknown->state);
        self::assertNull($stillUnknown->providerReceiptId);
        self::assertEquals($allocation->body, $app->make(LedgerQueries::class)->receipt($allocation->receiptRef));
        $this->assertBalance($obligation, 5000, 5000, 0);
        $this->assertCharges([5000], 1);
        $driver->setOffline(false);
        $registered = $fiscal->lookupIntent($scope, $intent->intentRef, $now->modify('+25 hours'));
        self::assertSame(FiscalIntentState::Registered, $registered->state);
        self::assertNotEmpty($registered->providerReceiptId);
        self::assertSame($registered->providerReceiptId, $fiscal->findIntentByExternalId($scope, $intent->externalId)?->providerReceiptId);
        self::assertSame([$intent->intentRef], array_column($fiscal->syncActualEffectsForRoot($scope, 'root-a', self::PROFILE), 'intentRef'));
        self::assertCount(1, $driver->recordedRegisters);
        self::assertCount(2, $driver->recordedLookups);
    }

    private function financialScope(): FinancialScope
    {
        $scope = Provider::scope();

        return new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, self::PROFILE);
    }

    /** @return array<string, int> */
    private function runWorker(): array
    {
        return $this->application()->make(CollectionRecoveryWorker::class)->run(Provider::scope(), self::PROFILE, 1);
    }

    private function makeReady(): void
    {
        $this->owner()->update("UPDATE billium_collection_work SET next_attempt_at = clock_timestamp() - interval '1 second' WHERE next_attempt_at IS NOT NULL");
        $this->owner()->update("UPDATE billium_execution_dispatch_work SET due_at = clock_timestamp() - interval '1 second' WHERE state = 'due'");
    }

    /** @return array<string, mixed> */
    private function metrics(?DateTimeImmutable $asOf = null, string $seller = 'seller-a'): array
    {
        $runbook = file_get_contents(dirname(__DIR__, 4).'/docs/operations/collection.md');
        self::assertIsString($runbook);
        self::assertSame(1, preg_match('/<!-- collection-metrics -->\R```sql\R(.+?)\R```/s', $runbook, $matches));

        $connection = $this->application()->make(DatabaseManager::class)->connection();

        return self::assoc($connection->selectOne(
            $matches[1], [self::INSTALLATION, $seller, 'test', 'source-a', self::PROFILE, ($asOf ?? new DateTimeImmutable)->format('Y-m-d H:i:s.uP')],
        ));
    }

    private function assertBalance(string $obligation, int $due, int $allocated, int $open): void
    {
        self::assertSame(['amount_due_minor' => $due, 'allocated_minor' => $allocated, 'open_minor' => $open],
            $this->row('SELECT amount_due_minor, allocated_minor, open_minor FROM billium_obligation WHERE obligation_ref = ?', [$obligation]));
        self::assertSame($due, $allocated + $open);
    }

    /** @param list<int> $captured */
    private function assertCharges(array $captured, int $calls): void
    {
        $actual = [];
        foreach ((new DurableJournal($this->journal))->records() as $record) {
            if ($record instanceof ProviderRecord && $record->kind === EffectKind::Charge && $record->status === 'succeeded') {
                $actual[] = $record->effectAmounts['captured'];
            }
        }
        self::assertSame($captured, $actual);
        self::assertSame($calls, UnknownResultTest::events($this->journal, 'provider.call'));
    }
}
