<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Data\AuthorizeCollection;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Internal\Services\DatabaseDunningCaseService;
use Billium\Collection\Internal\Work\CollectionRecoveryWorker;
use Billium\Collection\Internal\Work\CollectionWork;
use Billium\Collection\Internal\Work\CollectionWorkClaim;
use Billium\Collection\Internal\Work\DueCollectionWorker;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\P7\Support\CollectionAdmissionScenario as Scenario;
use Billium\Core\Tests\P7\Support\ExecutableCollectionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\CollectionExecutionGuard;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Obligations\AmendObligationDuePolicy;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Testkit\Drivers\ResponseMode;
use Closure;
use DateTimeImmutable;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CollectionRecoveryTest extends ExecutableCollectionTestCase
{
    protected const int LOOKUP_RETENTION_SECONDS = 37;

    private const string PROFILE = 'remote-billium';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner', $this->collectionScope(), 'agreement-a', 'component-a', RecurringOwnerMode::BilliumScheduler, 'schedule',
        ));
    }

    public function test_null_due_requires_explicit_authorization_and_future_case_waits(): void
    {
        $asOf = new DateTimeImmutable;
        self::assertSame([], $this->schedule($asOf)['authorized']);
        $this->setDue('obligation-a', $asOf->modify('-1 minute'));
        $this->owner()->update('UPDATE billium_collection_dunning_case SET next_attempt_at = ?, case_revision = case_revision + 1', [$asOf->modify('+1 hour')->format(DATE_ATOM)]);
        self::assertSame([], $this->schedule($asOf)['authorized']);
        self::assertSame(0, $this->rows('billium_collection_step'));
        $this->owner()->update('UPDATE billium_collection_dunning_case SET next_attempt_at = ?, case_revision = case_revision + 1', [$asOf->modify('-1 minute')->format(DATE_ATOM)]);
        $this->setDue('obligation-a', null);
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        self::assertSame(5000, $step->amountMinor);
        self::assertSame(false, $this->value('SELECT automatic_due FROM billium_collection_work'));
    }

    public function test_keyset_scheduler_replay_rechecks_open_after_concurrent_allocation(): void
    {
        $asOf = new DateTimeImmutable;
        $due = $asOf->modify('-1 minute');
        $this->setDue('obligation-a', $due);
        $this->application()->make(Ledger::class)->recordObligation(new RecordObligation('obligation-b', $this->account(), 'obligation-b',
            'basis-b', 'customer-a', 'component-a', $this->money(5000), self::digest(), duePolicy: $this->duePolicy($due)))->orThrow();
        $this->dunningCaseService()->openCase(new OpenDunningCase('open-b', $this->collectionScope(), 'case-b', 'account-a',
            'obligation-b', 1, 'policy-a', 1, 'RUB', 5000, openedAt: $due));
        $this->atConsentRead(fn () => $this->allocate(2500));

        $first = $this->schedule($asOf, 1);
        self::assertCount(1, $first['authorized']);
        self::assertSame(2500, $first['authorized'][0]->amountMinor);
        self::assertIsString($first['nextCursor']);
        $second = $this->schedule($asOf, 1, $first['nextCursor']);
        self::assertCount(1, $second['authorized']);
        self::assertSame('case-b', $second['authorized'][0]->caseRef);
        self::assertNull($second['nextCursor']);
        self::assertSame([], $this->schedule($asOf, 1)['authorized']);
        self::assertSame(2, $this->rows('billium_collection_work', 'automatic_due'));
        self::assertSame(2, $this->rows('billium_outbox_command'));
        self::assertSame(0, $this->providerCalls());
    }

    public function test_below_threshold_after_discovery_refuses_one_case_and_continues_the_page(): void
    {
        $asOf = new DateTimeImmutable;
        $due = $asOf->modify('-1 minute');
        $this->owner()->update(
            'UPDATE billium_collection_dunning_case SET next_attempt_at = ?, case_revision = case_revision + 1 WHERE case_ref = ?',
            [$asOf->modify('+1 day')->format(DATE_ATOM), 'case-a'],
        );
        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            'policy-threshold',
            $this->collectionScope(),
            'policy-threshold',
            1,
            [CollectionChannel::SavedMethod],
            [new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 0)],
            1,
            3,
            [DeclineCategory::SoftDecline],
            86400,
            minOutstandingMinor: 1000,
            thresholdCurrency: 'RUB',
        ));
        $ledger = $this->application()->make(Ledger::class);
        foreach (['obligation-low-a', 'obligation-low-b'] as $ref) {
            $ledger->recordObligation(new RecordObligation(
                $ref, $this->account(), $ref, 'basis-'.$ref, 'customer-a', 'component-a', $this->money(5000), self::digest(), duePolicy: $this->duePolicy($due),
            ))->orThrow();
            $this->dunningCaseService()->openCase(new OpenDunningCase(
                'open-'.$ref, $this->collectionScope(), 'case-'.$ref, 'account-a', $ref, 1, 'policy-threshold', 1, 'RUB', 5000, openedAt: $due,
            ));
        }
        $this->atConsentRead(function () use ($ledger): void {
            static $allocated = false;
            if ($allocated) {
                return;
            }
            $allocated = true;
            $ledger->recordCustomerCredit(new RecordCustomerCredit(
                'credit-low', $this->account(), 'credit-low', 'incoming-low', $this->money(4500),
            ))->orThrow();
            $ledger->allocate(new Allocate(
                'allocation-low', $this->account(), 'allocation-low', 'obligation-low-a', $this->money(4500), customerCreditRef: 'credit-low',
            ))->orThrow();
        });

        $page = $this->schedule($asOf);

        self::assertSame(1, $page['refused']);
        self::assertCount(1, $page['authorized']);
        self::assertSame('case-obligation-low-b', $page['authorized'][0]->caseRef);
        self::assertSame(1, $this->rows('billium_collection_step'));
    }

    public function test_whole_allocation_or_due_policy_change_after_discovery_creates_no_step(): void
    {
        $asOf = new DateTimeImmutable;
        $this->setDue('obligation-a', $asOf->modify('-1 minute'));
        $this->atConsentRead(fn () => $this->setDue('obligation-a', null));
        self::assertSame(1, $this->schedule($asOf)['refused']);
        self::assertSame(0, $this->rows('billium_collection_work'));
        $this->setDue('obligation-a', $asOf->modify('-1 minute'));
        $this->atConsentRead(fn () => $this->allocate(5000));
        self::assertSame(1, $this->schedule($asOf)['refused']);
        self::assertSame(0, $this->rows('billium_collection_step'));
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    public function test_skip_locked_and_bounded_claims(): void
    {
        $asOf = new DateTimeImmutable;
        $this->setDue('obligation-a', $asOf->modify('-1 minute'));
        $owner = TestEnvironment::pdo(owner: true);
        $owner->beginTransaction();
        try {
            $owner->query('SELECT id FROM billium_collection_dunning_case FOR UPDATE');
            self::assertSame(1, $this->schedule($asOf)['refused']);
        } finally {
            $owner->rollBack();
        }
        self::assertCount(1, $this->schedule($asOf)['authorized']);
        $owner->beginTransaction();
        try {
            $owner->query('SELECT step_id FROM billium_collection_work FOR UPDATE');
            self::assertSame([], $this->claim()['claims']);
        } finally {
            $owner->rollBack();
        }
        self::assertCount(1, $this->claim()['claims']);
        self::assertSame([], $this->claim()['claims']);
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->claim(101));
        self::assertSame(0, $this->providerCalls());
    }

    public function test_after_enqueue_and_admission_replays_the_original_command_and_receipt(): void
    {
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $payment = $this->commandFromOutbox();
        $payments = $this->application()->make(RootPaymentCommands::class);
        $first = $payments->admit(Fixture::scope(), $payment)->orThrow();
        $this->runWorker();
        $replay = $payments->admit(Fixture::scope(), $payment)->orThrow();
        self::assertTrue($replay->replayed);
        self::assertSame($first->receiptId, $replay->receiptId);
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(1, $this->providerCalls());
        $this->applyEvidence();
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['done']);
        self::assertSame('settled', $this->value('SELECT status FROM billium_collection_dunning_case'));
        self::assertSame(5000, $this->value('SELECT allocated_minor FROM billium_payment'));
        self::assertSame(1, $this->rows('billium_collection_dispatch_permit'));
    }

    /** @return iterable<string, array{bool}> */
    public static function responseFailures(): iterable
    {
        yield 'process dies after acceptance' => [true];
        yield 'reply lost after acceptance' => [false];
    }

    #[DataProvider('responseFailures')]
    public function test_unknown_recovers_by_lookup_and_waits_for_ledger(bool $crash): void
    {
        $app = $this->application();
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        Fixture::host($app, $this->journal, cap: $this->capability, mode: $crash ? ResponseMode::Success : ResponseMode::AcceptedThenTimeout,
            checkpoint: static function (string $phase) use ($crash): void {
                if ($crash && $phase === 'after_accept') {
                    throw new Error('simulated process death');
                }
            });
        try {
            $this->runWorker();
            self::assertFalse($crash);
        } catch (Error $e) {
            self::assertTrue($crash);
            self::assertSame('simulated process death', $e->getMessage());
        }
        self::assertSame(1, $this->providerCalls());
        $this->makeReady();
        $credentials = $app->make(MerchantCredentials::class);
        $app->instance(MerchantCredentials::class, new readonly class($credentials) implements MerchantCredentials
        {
            public function __construct(private MerchantCredentials $driver) {}

            public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
            {
                if ($purpose === 'lookup') {
                    throw new ProblemException(ProblemCode::LookupUnavailable, 'simulated original lookup unavailable');
                }

                return $this->driver->resolve($revision, $purpose);
            }
        });
        self::assertSame(1, $this->runWorker()['due']);
        self::assertSame('parked_unknown', $this->value('SELECT status FROM billium_collection_dunning_case'));
        self::assertSame(0, $this->value('SELECT business_decline_attempts_used FROM billium_collection_dunning_case'));
        self::assertSame(1, $this->rows('billium_collection_step'));
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(1, $this->providerCalls());
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->authorizeCollection(new AuthorizeCollection(
            new PlanCollectionStep('next', $this->collectionScope(), 'case-a', 'step-next', 2, 'intent-next', 'root-next', 5000, new DateTimeImmutable),
            Fixture::scope(), 'consent-1', 1, 1, self::PROFILE,
        )));

        $app->instance(MerchantCredentials::class, $credentials);
        $this->makeReady();
        $this->runWorker();
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.lookup'));
        self::assertSame(1, $this->providerCalls());
        $observation = $this->reduceEvidence();
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['due']);
        self::assertSame('unknown', $this->value('SELECT status FROM billium_collection_step'));
        $this->applyObservation($observation);
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['done']);
        self::assertSame(1, $this->rows('billium_payment'));
        self::assertSame(1, $this->rows('billium_collection_step', "status = 'succeeded'"));
    }

    public function test_stale_worker_retains_evidence_but_cannot_acknowledge_or_project(): void
    {
        $app = $this->application();
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        Fixture::host($app, $this->journal, cap: $this->capability, checkpoint: function (string $phase): void {
            if ($phase === 'after_accept') {
                $this->owner()->update("UPDATE billium_collection_work SET next_attempt_at = clock_timestamp() - interval '1 second'");
                $winner = $this->claim()['claims'];
                self::assertCount(1, $winner);
                self::assertSame(2, $winner[0]->fence);
            }
        });
        self::assertSame(1, $this->runWorker()['stale']);
        self::assertSame('leased', $this->value('SELECT status FROM billium_collection_work'));
        self::assertSame(2, $this->value('SELECT fence FROM billium_collection_work'));
        self::assertSame(1, $this->rows('billium_execution_provider_work'));
        self::assertSame('dispatched', $this->value('SELECT status FROM billium_collection_step'));
        $this->applyEvidence();
        $this->makeReady();
        $stale = $this->claim()['claims'][0];
        $this->makeReady();
        self::assertCount(1, $this->claim()['claims']);
        $step = $this->dunningQueries()->findStep($this->collectionScope(), 'step-a');
        self::assertNotNull($step);
        $this->assertRefused(ProblemCode::RecoveryRequired, fn () => $app->make(DatabaseDunningCaseService::class)->transitionClaimedStep(
            new TransitionCollectionStep('stale', $this->collectionScope(), 'case-a', 'step-a', CollectionStepStatus::Unknown,
                $step->stepRevision, new DateTimeImmutable), $stale,
        ));
        self::assertSame('dispatched', $this->value('SELECT status FROM billium_collection_step'));
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['done']);
        self::assertSame(1, $this->providerCalls());
    }

    public function test_frozen_lookup_window_parks_and_late_evidence_still_settles(): void
    {
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $this->runWorker();
        $this->owner()->update("UPDATE billium_execution_attempt SET started_at = clock_timestamp() - interval '38 seconds'");
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['parked']);
        self::assertSame('lookup_window_unavailable', $this->value('SELECT last_reason FROM billium_collection_work'));
        self::assertSame('unknown', $this->value('SELECT status FROM billium_collection_step'));
        self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.lookup'));
        self::assertSame(0, $this->rows('billium_payment'));

        $this->applyEvidence();
        self::assertTrue($this->application()->make(CollectionRecoveryWorker::class)->reconcileParked(Fixture::scope(), self::PROFILE, 'step-a'));
        self::assertSame('succeeded', $this->value('SELECT status FROM billium_collection_step'));
        self::assertSame('done', $this->value('SELECT status FROM billium_collection_work'));
        self::assertSame(1, $this->providerCalls());
    }

    public function test_only_applied_classified_decline_enables_a_new_business_intent(): void
    {
        $app = $this->application();
        $asOf = new DateTimeImmutable;
        $this->setDue('obligation-a', $asOf->modify('-1 minute'));
        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            'retry-policy', $this->collectionScope(), 'retry-policy', 1, [CollectionChannel::SavedMethod],
            [new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 0), new DunningPolicyStepDefinition(2, CollectionChannel::SavedMethod, 0)],
            2, 1, [DeclineCategory::SoftDecline], 86400,
        ));
        $this->dunningCaseService()->openCase(new OpenDunningCase('retry-case', $this->collectionScope(), 'case-b', 'account-a',
            'obligation-a', 2, 'retry-policy', 1, 'RUB', 5000, openedAt: $asOf->modify('-1 minute')));
        $first = $this->dunningCaseService()->authorizeCollection(Scenario::command('b', 'case-b'));
        Fixture::host($app, $this->journal, cap: $this->capability, mode: ResponseMode::Declined);
        $this->runWorker();
        $this->applyEvidence();
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['parked']);
        self::assertSame(0, $this->value("SELECT business_decline_attempts_used FROM billium_collection_dunning_case WHERE case_ref = 'case-b'"));
        self::assertTrue($app->make(CollectionRecoveryWorker::class)->reconcileParked(Fixture::scope(), self::PROFILE, $first->stepRef,
            static fn (): DeclineCategory => DeclineCategory::SoftDecline));
        self::assertSame(1, $this->value("SELECT business_decline_attempts_used FROM billium_collection_dunning_case WHERE case_ref = 'case-b'"));
        self::assertSame(1, $this->value('SELECT delivery_claims FROM billium_collection_work'));

        $next = $app->make(DueCollectionWorker::class)->schedule(Fixture::scope(), self::PROFILE, 1, 'consent-1', 1, 2, new DateTimeImmutable);
        self::assertCount(1, $next['authorized']);
        self::assertSame(2, $next['authorized'][0]->stepNo);
        self::assertNotSame($first->operationRef, $next['authorized'][0]->operationRef);
        self::assertNotSame($first->businessPaymentIntentRef, $next['authorized'][0]->businessPaymentIntentRef);
        self::assertSame(1, $this->providerCalls());
    }

    public function test_unavailable_guard_exhausts_only_the_transport_budget(): void
    {
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $this->application()->offsetUnset(CollectionExecutionGuard::class);
        for ($i = 0; $i < 4; $i++) {
            $this->makeReady();
            self::assertSame(1, $this->runWorker()['due']);
        }
        $this->makeReady();
        self::assertSame(1, $this->runWorker()['parked']);
        self::assertSame('delivery_budget_exhausted', $this->value('SELECT last_reason FROM billium_collection_work'));
        self::assertSame(4, $this->value('SELECT delivery_claims FROM billium_collection_work'));
        self::assertSame(0, $this->value('SELECT business_decline_attempts_used FROM billium_collection_dunning_case'));
        self::assertSame(1, $this->rows('billium_outbox_command'));
        self::assertSame(0, $this->providerCalls());
        self::assertSame(0, $this->runWorker()['due']);
    }

    public function test_revoke_after_enqueue_produces_durable_refusal_and_no_effect_closure(): void
    {
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $payment = $this->commandFromOutbox();
        $this->consentService()->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'revoke', scope: $this->collectionScope(), consentRef: 'consent-1', tombstoneRef: 'revoke-a',
            sourceRevision: 2, cancellationGeneration: 0, reason: 'revoked', evidenceDigest: self::digest(),
            tombstoneKind: TombstoneKind::ConsentRevoked, targetAuthorizationRef: $step->stepAuthorizationRef, targetBusinessIntentRef: 'intent-a',
        ));
        self::assertSame(1, $this->runWorker()['done']);
        $payments = $this->application()->make(RootPaymentCommands::class);
        $reply = $payments->admit(Fixture::scope(), $payment);
        self::assertNotNull($reply->receiptId);
        self::assertTrue($reply->replayed);
        self::assertSame(ProblemCode::OperationFinancialHold, $reply->problem);
        self::assertSame('cancelled', $this->value('SELECT status FROM billium_collection_step'));
        self::assertSame(1, $this->rows('billium_collection_no_effect'));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(0, $this->providerCalls());
    }

    public function test_seal_recovery_and_new_activation_never_dispatch_old_unstarted_step(): void
    {
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $this->authority->issueTransition('seal', 'seal', [1]);
        $this->installation()->seal('seal', 1);
        $this->assertRefused(ProblemCode::InstallationQuarantined, fn () => $this->runWorker());
        $this->authority->issueTransition('open', 'new-owner', ['owner-2', 2, 2, 2, 'retirement-1']);
        $this->installation()->openSession('new-owner', 'owner-2', 2, 2, 2, 'retirement-1');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2, [FinancialMode::Recovery, FinancialMode::Active]));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        self::assertSame([], $this->claim()['claims']);

        $this->authority->issueTransition('activate', 'activate', [2, 'certificate-2', 'admission-2', 'evidence-2']);
        $this->installation()->activateSession('activate', 2, 'certificate-2', 'admission-2', 'evidence-2');
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
        self::assertSame(1, $this->runWorker()['parked']);
        self::assertSame('collection_epoch_changed', $this->value('SELECT last_reason FROM billium_collection_work'));
        self::assertSame(0, $this->rows('billium_execution_operation'));
        self::assertSame(0, $this->providerCalls());
    }

    /** @return array{authorized: list<CollectionStepView>, refused: int, nextCursor: ?string} */
    private function schedule(DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): array
    {
        return $this->application()->make(DueCollectionWorker::class)
            ->schedule(Fixture::scope(), self::PROFILE, 1, 'consent-1', 1, 1, $asOf, $limit, $cursor);
    }

    private function duePolicy(?DateTimeImmutable $due): ObligationDuePolicy
    {
        return new ObligationDuePolicy($due, 'policy.v1', 'host-due-policy', self::digest());
    }

    private function setDue(string $ref, ?DateTimeImmutable $due): void
    {
        $this->application()->make(Ledger::class)->amendObligationDuePolicy(new AmendObligationDuePolicy(
            'due-'.$ref.'-'.hrtime(true), $this->account(), $ref, $this->duePolicy($due),
        ))->orThrow();
    }

    private function atConsentRead(Closure $checkpoint): void
    {
        $this->application()->instance(CollectionConsentGuard::class, new class($checkpoint) implements CollectionConsentGuard
        {
            private bool $reached = false;

            public function __construct(private readonly Closure $checkpoint) {}

            public function currentAuthority(CollectionScope $scope, string $billingAccountRef, string $consentRef): HostConsentAuthoritySnapshot
            {
                if (! $this->reached) {
                    $this->reached = true;
                    ($this->checkpoint)();
                }

                return (new Scenario)->currentAuthority($scope, $billingAccountRef, $consentRef);
            }
        });
    }

    /** @return array{claims: list<CollectionWorkClaim>, exhausted: int} */
    private function claim(int $limit = 1): array
    {
        return $this->application()->make(CollectionWork::class)->claim(new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', self::PROFILE), $limit, 30);
    }

    /** @return array{done: int, due: int, parked: int, stale: int} */
    private function runWorker(): array
    {
        return $this->application()->make(CollectionRecoveryWorker::class)->run(Fixture::scope(), self::PROFILE, 1);
    }

    private function makeReady(): void
    {
        $this->owner()->update("UPDATE billium_collection_work SET next_attempt_at = clock_timestamp() - interval '1 second' WHERE next_attempt_at IS NOT NULL");
        $this->owner()->update("UPDATE billium_execution_dispatch_work SET due_at = clock_timestamp() - interval '1 second' WHERE state = 'due'");
        $this->owner()->update("UPDATE billium_execution_dispatch_work SET lease_expires_at = clock_timestamp() - interval '1 second' WHERE state = 'leased'");
    }

    private function providerCalls(): int
    {
        return UnknownResultTest::events($this->journal, 'provider.call');
    }

    private function reduceEvidence(): PaymentObservation
    {
        $app = $this->application();
        $work = $this->value('SELECT work_ref FROM billium_execution_provider_work');
        self::assertIsString($work);
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertCount(1, $result->observationCommandRefs);

        return OutcomeDeliveryTest::attest($app, $this->journal, $result->observationCommandRefs[0]);
    }

    private function applyObservation(PaymentObservation $observation): void
    {
        $this->application()->make(ObservationApplier::class)->apply(ObservationFactory::json($observation->raw));
    }

    private function applyEvidence(): void
    {
        $this->applyObservation($this->reduceEvidence());
    }
}
