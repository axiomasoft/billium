<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCaseTransitionResult;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Tests\P7\Support\CollectionAdmissionScenario as Scenario;
use Billium\Core\Tests\P7\Support\ExecutableCollectionTestCase;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReallocationMode;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use DateTimeImmutable;
use DateTimeZone;
use Error;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('ACC-CON-13')]
#[Group('ACC-CON-21')]
final class CollectionAdmissionTest extends ExecutableCollectionTestCase
{
    protected const int PER_CHARGE_CAP_MINOR = 5000;

    protected const int PER_PERIOD_CAP_MINOR = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-billium', $this->collectionScope(), 'agreement-a', 'component-a', RecurringOwnerMode::BilliumScheduler, 'billium-schedule',
        ));
    }

    public function test_allocation_changes_the_frozen_amount_and_replays_keep_one_command(): void
    {
        $this->allocate(2000);
        $case = $this->dunningQueries()->findCase($this->collectionScope(), 'case-a');
        self::assertNotNull($case);
        self::assertNull($case->componentRef);
        $owner = $this->dunningQueries()->findActiveRecurringOwner($this->collectionScope(), 'component-a');
        self::assertNotNull($owner);
        $service = $this->dunningCaseService();
        $step = $service->authorizeCollection(Scenario::command());
        self::assertSame(3000, $step->amountMinor);
        self::assertSame(CollectionStepStatus::Authorized, $step->status);
        self::assertNotNull($step->stepAuthorizationRef);
        self::assertSame($step->id, $service->authorizeCollection(Scenario::command())->id);
        $this->assertRefused(ProblemCode::IdempotencyConflict, fn () => $service->authorizeCollection(Scenario::command(amount: 4000)));
        self::assertSame(3000, $this->commandFromOutbox()->money->minor);
        self::assertSame(1, $this->rows('billium_collection_authorization'));
        self::assertSame($owner->id, $this->value('SELECT recurring_owner_id FROM billium_collection_authorization'));
        self::assertSame(1, $this->rows('billium_settlement_target'));
        self::assertSame(1, $this->rows('billium_outbox_command'));
        self::assertSame(0, $this->rows('billium_execution_operation'));
    }

    public function test_success_without_an_explicit_balance_preserves_a_partially_collected_obligation(): void
    {
        $this->allocate(1000);
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command(amount: 2500));

        $complete = $this->completeCharge($step);

        self::assertSame(CollectionStepStatus::Succeeded, $complete->step->status);
        self::assertSame(DunningCaseStatus::Open, $complete->case->status);
        self::assertSame(1500, $complete->case->currentOpenMinor);
        self::assertSame(1500, $this->value('SELECT open_minor FROM billium_obligation'));
        self::assertSame(1500, $this->value('SELECT current_open_minor FROM billium_collection_dunning_case'));
    }

    public function test_monthly_consent_cap_allows_later_renewal_after_a_settled_charge(): void
    {
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        self::assertSame(DunningCaseStatus::Settled, $this->completeCharge($step)->case->status);
        $issuedAt = $this->value('SELECT issued_at FROM billium_collection_authorization');
        self::assertIsString($issuedAt);
        $earlier = (new DateTimeImmutable($issuedAt))->setTimezone(new DateTimeZone('UTC'));
        $nextMonth = $earlier->modify('first day of next month')->setTime(0, 0);

        try {
            Date::setTestNow($earlier);
            $this->openRenewalCase('same-month', $earlier->modify('-1 hour'));
            try {
                $this->dunningCaseService()->authorizeCollection(Scenario::command('same-month', 'case-same-month'));
                self::fail('The settled charge must count against the same month');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
                self::assertSame('per_period_cap_exceeded', $error->detail);
            }
            self::assertSame(1, $this->rows('billium_collection_authorization'));

            Date::setTestNow($nextMonth);
            $this->openRenewalCase('next-month', $nextMonth);
            $renewal = $this->dunningCaseService()->authorizeCollection(Scenario::command('next-month', 'case-next-month', scheduled: $nextMonth));

            self::assertSame(CollectionStepStatus::Authorized, $renewal->status);
            self::assertSame(5000, $renewal->amountMinor);
            self::assertSame(2, $this->rows('billium_collection_authorization'));
        } finally {
            Date::setTestNow();
        }
    }

    public function test_provider_owned_obligation_refuses_collection_when_the_case_omits_its_component(): void
    {
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-provider', $this->collectionScope(), 'agreement-provider', 'component-provider',
            RecurringOwnerMode::ProviderAutonomousSubscription, 'provider-subscription',
        ));
        $this->application()->make(Ledger::class)->recordObligation(new RecordObligation(
            'provider-obligation', $this->account(), 'obligation-provider', 'basis-provider', 'customer-a',
            'component-provider', $this->money(5000), self::digest(),
        ))->orThrow();
        $service = $this->dunningCaseService();
        $case = $service->openCase(new OpenDunningCase(
            'open-provider', $this->collectionScope(), 'case-provider', 'account-a', 'obligation-provider', 1, 'policy-a', 1, 'RUB', 5000,
            openedAt: new DateTimeImmutable('-1 hour'),
        ));
        self::assertNull($case->componentRef);
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $service->authorizeCollection(Scenario::command('provider', $case->caseRef)));
        self::assertSame(0, $this->rows('billium_collection_step'));
        self::assertSame(0, $this->rows('billium_collection_authorization'));
        self::assertSame(0, $this->rows('billium_settlement_intent'));
        self::assertSame(0, $this->rows('billium_outbox_command'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function overlappingRootIdentities(): iterable
    {
        yield 'operation reference' => ['root-a', 'intent-regular'];
        yield 'business intent reference' => ['root-regular', 'intent-a'];
    }

    #[DataProvider('overlappingRootIdentities')]
    public function test_collection_cannot_reserve_either_reference_of_an_admitted_root(string $operationRef, string $intentRef): void
    {
        $app = $this->application();
        $origin = new OriginDecision(Fixture::scope(), $operationRef, $intentRef, 'checkout', 'remote-billium', 1, null, self::digest(), 'terms-v1', null);
        Fixture::host($app, $this->journal, [$origin], cap: $this->capability);
        $regular = Fixture::wire(['operationRef' => $operationRef, 'source' => ['context' => 'checkout', 'intentRef' => $intentRef]]);
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $regular)->orThrow();

        $this->assertRefused(ProblemCode::IdempotencyConflict, fn () => $this->dunningCaseService()->authorizeCollection(Scenario::command()));
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(0, $this->rows('billium_collection_authorization'));
        self::assertSame(0, $this->rows('billium_settlement_intent'));
        self::assertSame(0, $this->rows('billium_outbox_command', "command_type = 'collection.execute.v1'"));
    }

    public function test_twenty_workers_create_one_executable_step(): void
    {
        $accepted = 0;
        foreach (ConcurrencyStand::run(Scenario::class, 20, []) as $worker) {
            $result = self::ok($worker);
            if ($result['accepted'] === true) {
                $accepted++;
            } else {
                self::assertSame(ProblemCode::IdempotencyConflict->value, $result['problem']);
            }
        }
        self::assertSame(1, $accepted);
        self::assertSame(1, $this->rows('billium_collection_step'));
        self::assertSame(1, $this->rows('billium_collection_authorization'));
        self::assertSame(1, $this->rows('billium_settlement_intent'));
        self::assertSame(1, $this->rows('billium_outbox_command'));
    }

    public function test_exception_after_command_write_rolls_back_every_owner_and_the_key_can_retry(): void
    {
        $app = $this->application();
        $real = $app->make(CommandOutbox::class);
        $app->instance(CommandOutbox::class, new readonly class($real) implements CommandOutbox
        {
            public function __construct(private CommandOutbox $real) {}

            public function record(TransactionContext $context, PreparedInternalCommand $command): int
            {
                $this->real->record($context, $command);

                throw new RuntimeException('before commit');
            }
        });
        try {
            $this->dunningCaseService()->authorizeCollection(Scenario::command());
            self::fail('Fault did not abort the root');
        } catch (RuntimeException $e) {
            self::assertSame('before commit', $e->getMessage());
        }
        foreach (['billium_collection_step', 'billium_collection_authorization', 'billium_settlement_intent',
            'billium_settlement_target', 'billium_outbox_command', 'billium_outbox_command_delivery'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
        $app->instance(CommandOutbox::class, $real);
        self::assertSame(5000, $this->dunningCaseService()->authorizeCollection(Scenario::command())->amountMinor);
    }

    public function test_crash_after_provider_acceptance_keeps_one_charge_and_late_excess_as_customer_funds(): void
    {
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $app = $this->application();
        $payment = $this->commandFromOutbox();
        $admit = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $payment)->orThrow();
        self::assertSame('admitted', $admit->body['status']);
        Fixture::host($app, $this->journal, cap: $this->capability, checkpoint: static function (string $phase): void {
            if ($phase === 'after_accept') {
                throw new Error('crash after provider acceptance');
            }
        });
        $worker = $app->make(AttemptWorker::class);
        $lease = new DateTimeImmutable('+2 seconds');
        try {
            $worker->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', $lease);
            self::fail('Expected lost dispatch result');
        } catch (Error $e) {
            self::assertSame('crash after provider acceptance', $e->getMessage());
        }
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(1, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame(0, $this->rows('billium_payment'));
        $this->allocate(2000);
        $this->openCase('case-b', 2);
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->authorizeCollection(Scenario::command('b', 'case-b')));

        while (new DateTimeImmutable < $lease) {
            usleep(20_000);
        }
        $worker->recover(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'))->orThrow();
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.call'));
        $evidence = CallbackCustodyTest::providerInput($app);
        $result = $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($evidence));
        self::assertCount(1, $result->observationCommandRefs);
        $observation = OutcomeDeliveryTest::attest($app, $this->journal, $result->observationCommandRefs[0]);
        self::assertSame(0, $this->rows('billium_payment'));
        $transition = new TransitionCollectionStep('success', $this->collectionScope(), $step->caseRef, $step->stepRef,
            CollectionStepStatus::Succeeded, $step->stepRevision + 1, new DateTimeImmutable, outcomeEvidenceDigest: $observation->contentDigest);
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->transitionStep($transition));
        $app->make(ObservationApplier::class)->apply(ObservationFactory::json($observation->raw));
        self::assertSame(5000, $this->value('SELECT captured_minor FROM billium_payment'));
        self::assertSame(3000, $this->value('SELECT allocated_minor FROM billium_payment'));
        self::assertSame(['amount' => '20.00', 'currency' => 'RUB'], $app->make(LedgerQueries::class)->paymentStatus('root-a')['spendable']);
        $complete = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep('success-after-application', $this->collectionScope(),
            $step->caseRef, $step->stepRef, CollectionStepStatus::Succeeded, $step->stepRevision + 1, new DateTimeImmutable, outcomeEvidenceDigest: $observation->contentDigest));
        self::assertSame(CollectionStepStatus::Succeeded, $complete->step->status);
        self::assertSame(1, $this->rows('billium_outbox_command', "command_type = 'collection.execute.v1'"));
    }

    public function test_refund_reversal_does_not_create_an_automatic_collection_step(): void
    {
        $app = $this->application();
        $ledger = $app->make(Ledger::class);
        $ledger->declareSettlementIntent(new DeclareSettlementIntent('original-intent', $this->account(), 'original', 'RUB', ['obligation-a']))->orThrow();
        $applier = $app->make(ObservationApplier::class);
        $fields = ['sourceAuthority' => 'remote-billium', 'businessIntentRef' => 'original'];
        $applier->apply(ObservationFactory::json(ObservationFactory::charge('original-payment', 1, 5000, fields: $fields)));
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->authorizeCollection(Scenario::command('settled')));
        $applier->apply(ObservationFactory::json(ObservationFactory::charge('original-payment', 2, 5000, 1200, 2, $fields)));
        $ledger->reallocatePayment(new ReallocatePayment('reallocate', self::INSTALLATION, 'seller-a', 'test',
            'original-payment', 'refund/1', ReallocationMode::Reversal))->orThrow();
        self::assertSame(1200, $this->value('SELECT open_minor FROM billium_obligation'));
        self::assertSame(1, $this->rows('billium_allocation', "cause = 'refund' AND amount_minor < 0"));
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dunningCaseService()->authorizeCollection(Scenario::command('refunded')));
        self::assertSame(0, $this->rows('billium_collection_step'));
        self::assertSame(0, $this->rows('billium_outbox_command', "command_type = 'collection.execute.v1'"));
    }

    private function completeCharge(CollectionStepView $step): DunningCaseTransitionResult
    {
        $app = $this->application();
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $this->commandFromOutbox())->orThrow();
        $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'))->orThrow();
        $evidence = CallbackCustodyTest::providerInput($app);
        $result = $app->make(EvidenceReducer::class)->reduce(CallbackCustodyTest::work($evidence));
        self::assertCount(1, $result->observationCommandRefs);
        $observation = OutcomeDeliveryTest::attest($app, $this->journal, $result->observationCommandRefs[0]);
        $app->make(ObservationApplier::class)->apply(ObservationFactory::json($observation->raw));

        return $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            'complete-'.$step->stepRef, $this->collectionScope(), $step->caseRef, $step->stepRef, CollectionStepStatus::Succeeded,
            $step->stepRevision + 1, new DateTimeImmutable, outcomeEvidenceDigest: $observation->contentDigest,
        ));
    }

    private function openRenewalCase(string $period, DateTimeImmutable $openedAt): void
    {
        $obligation = 'obligation-'.$period;
        $this->application()->make(Ledger::class)->recordObligation(new RecordObligation(
            'record-'.$period, $this->account(), $obligation, 'basis-'.$period, 'customer-a', 'component-a', $this->money(5000), self::digest(),
        ))->orThrow();
        $this->dunningCaseService()->openCase(new OpenDunningCase(
            'open-'.$period, $this->collectionScope(), 'case-'.$period, 'account-a', $obligation, 1, 'policy-a', 1, 'RUB', 5000, openedAt: $openedAt,
        ));
    }
}
