<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionParticipants;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Tests\P7\Support\CollectionAdmissionScenario as Scenario;
use Billium\Core\Tests\P7\Support\ExecutableCollectionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\Collection\CollectionOriginLookup;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CollectionGuardRevocationTest extends ExecutableCollectionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-billium', $this->collectionScope(), 'agreement-a', 'component-a', RecurringOwnerMode::BilliumScheduler, 'billium-schedule',
        ));
    }

    /** @return iterable<array{TombstoneKind, bool}> */
    public static function revocations(): iterable
    {
        foreach ([TombstoneKind::ConsentRevoked, TombstoneKind::HostCancelled] as $kind) {
            yield [$kind, false];
            yield [$kind, true];
        }
    }

    #[DataProvider('revocations')]
    public function test_revocation_before_admission_or_dispatch_closes_with_zero_provider_sends(TombstoneKind $kind, bool $admitted): void
    {
        $app = $this->application();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        self::assertNotNull($step->stepAuthorizationRef);
        $payment = $this->commandFromOutbox();
        $payments = $app->make(RootPaymentCommands::class);
        if ($admitted) {
            $payments->admit(Fixture::scope(), $payment)->orThrow();
        }
        $this->revoke($step->stepAuthorizationRef, $kind);

        if ($admitted) {
            $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dispatch());
        } else {
            $refused = $payments->admit(Fixture::scope(), $payment);
            self::assertSame(ProblemCode::OperationFinancialHold, $refused->problem);
            self::assertNotNull($refused->receiptId);
            self::assertSame($refused->receiptId, $payments->admit(Fixture::scope(), $payment)->receiptId);
            $droppedOrigin = Fixture::wire(['commandKey' => Fixture::key(91), 'money' => $payment->money->toWire()]);
            $this->assertRefused(ProblemCode::AuthDenied, fn () => $payments->admit(Fixture::scope(), $droppedOrigin)->orThrow());
        }
        $participants = $app->make(CollectionParticipants::class);
        $receipt = $participants->closeRevoked(Fixture::scope(), $step->stepAuthorizationRef);
        self::assertSame($receipt, $participants->closeRevoked(Fixture::scope(), $step->stepAuthorizationRef));
        self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame($admitted ? 1 : 0, $this->rows('billium_execution_operation'));
        self::assertSame(1, $this->rows('billium_collection_guard_tombstone', 'target_authorization_ref = ?', [$step->stepAuthorizationRef]));
        self::assertSame(1, $this->rows('billium_collection_step', "status = 'cancelled'"));
        self::assertSame(1, $this->rows('billium_collection_no_effect', 'closure_ref = ?', [$receipt]));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure', 'closure_ref = ? AND proof_kind = ?', [$receipt, $admitted ? 'not_dispatched' : 'not_admitted']));
        self::assertSame(1, $this->rows('billium_settlement_target'));
        self::assertSame('closed_no_effect', $this->value('SELECT status FROM billium_settlement_intent'));
    }

    public function test_revoked_collection_cannot_send_through_a_stale_regular_origin_lookup(): void
    {
        $app = $this->application();
        $authorizationRef = null;
        $reserveAndRevoke = function () use (&$authorizationRef): void {
            $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
            $authorizationRef = $step->stepAuthorizationRef;
            self::assertNotNull($authorizationRef);
            $this->revoke($authorizationRef, TombstoneKind::ConsentRevoked);
        };
        $lookup = $app->make(CollectionOriginLookup::class);
        $app->instance(CollectionOriginLookup::class, new readonly class($lookup, $reserveAndRevoke) implements CollectionOriginLookup
        {
            public function __construct(private CollectionOriginLookup $lookup, private Closure $afterMiss) {}

            public function findOrigin(ExecutionScope $scope, string $operationRef, string $businessIntentRef, ?TransactionContext $context = null): ?OriginDecision
            {
                $origin = $this->lookup->findOrigin($scope, $operationRef, $businessIntentRef, $context);
                if ($context === null && $origin === null) {
                    ($this->afterMiss)();
                }

                return $origin;
            }
        });

        $payments = $app->make(RootPaymentCommands::class);
        $regular = Fixture::wire(['money' => $this->money(5000)->toWire()]);
        try {
            $problem = $payments->admit(Fixture::scope(), $regular)->problem;
        } catch (ProblemException $exception) {
            $problem = $exception->problem;
        }
        if ($problem === null) {
            $this->dispatch();
        }

        self::assertNotNull($authorizationRef);
        self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(ProblemCode::AuthDenied, $problem);
        self::assertSame(0, $this->rows('billium_execution_operation'));
        self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame(ProblemCode::OperationFinancialHold, $payments->admit(Fixture::scope(), $this->commandFromOutbox())->problem);
    }

    public function test_revoke_after_dispatch_linearization_keeps_the_permit_and_reservation(): void
    {
        $app = $this->application();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $ref = $step->stepAuthorizationRef;
        self::assertNotNull($ref);
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $this->commandFromOutbox())->orThrow();
        Fixture::host($app, $this->journal, cap: $this->capability, checkpoint: function (string $phase) use ($ref): void {
            if ($phase === 'before_accept') {
                $this->revoke($ref, TombstoneKind::HostCancelled);
            }
        });
        $this->dispatch();

        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.call'));
        $permit = $this->value('SELECT permit_ref FROM billium_collection_dispatch_permit');
        self::assertIsString($permit);
        self::assertSame($permit, $this->value('SELECT collection_dispatch_permit_ref FROM billium_execution_attempt'));
        $this->assertRefused(ProblemCode::OperationNotCancellable, fn () => $app->make(CollectionParticipants::class)->closeRevoked(Fixture::scope(), $ref));
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(1, $this->rows('billium_collection_step', "status = 'dispatched'"));
    }

    public function test_durable_revoke_after_epoch_change_closes_an_unadmitted_step(): void
    {
        $app = $this->application();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $ref = $step->stepAuthorizationRef;
        self::assertNotNull($ref);
        self::assertSame(1, $this->value('SELECT financial_epoch FROM billium_collection_authorization'));
        $target = $this->value('SELECT id FROM billium_settlement_target');
        self::assertIsInt($target);

        $this->authority->issueTransition('seal', 'collection-seal', [1]);
        $this->installation()->seal('collection-seal', 1);
        $this->authority->issueTransition('open', 'collection-open', ['owner-2', 2, 2, 2, 'retirement-1']);
        $this->installation()->openSession('collection-open', 'owner-2', 2, 2, 2, 'retirement-1');
        $this->authority->issueTransition('activate', 'collection-activate', [2, 'certificate-2', 'admission-2', 'evidence-2']);
        $active = $this->installation()->activateSession('collection-activate', 2, 'certificate-2', 'admission-2', 'evidence-2');
        self::assertSame('active', $active->state);
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);

        $participants = $app->make(CollectionParticipants::class);
        $this->assertRefused(ProblemCode::OperationNotCancellable, fn () => $participants->closeRevoked(Fixture::scope(), $ref));
        $refused = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $this->commandFromOutbox());
        self::assertSame(ProblemCode::OperationFinancialHold, $refused->problem);
        $this->revoke($ref, TombstoneKind::ConsentRevoked);
        $closure = $participants->closeRevoked(Fixture::scope(), $ref);
        self::assertSame($closure, $participants->closeRevoked(Fixture::scope(), $ref));

        self::assertSame(0, $this->value('SELECT cancellation_generation FROM billium_collection_consent_guard'));
        self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(0, $this->rows('billium_execution_operation'));
        self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
        self::assertSame(1, $this->rows('billium_collection_step', "status = 'cancelled'"));
        self::assertSame(1, $this->rows('billium_collection_no_effect', "closure_ref = ? AND proof_kind = 'not_admitted' AND evidence_ref = 'revoke-a'", [$closure]));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure', "closure_ref = ? AND proof_kind = 'not_admitted'", [$closure]));
        self::assertSame('closed_no_effect', $this->value('SELECT status FROM billium_settlement_intent'));
        self::assertSame($target, $this->value('SELECT id FROM billium_settlement_target'));
    }

    public function test_allocation_after_admission_refuses_the_stale_amount_before_send(): void
    {
        $app = $this->application();
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command());
        self::assertNotNull($step->stepAuthorizationRef);
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $this->commandFromOutbox())->orThrow();
        $this->allocate(2000);
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->dispatch());
        $app->make(CollectionParticipants::class)->closeRevoked(Fixture::scope(), $step->stepAuthorizationRef);
        self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure', "proof_kind = 'not_dispatched'"));
        $this->openCase('case-b', 2);
        self::assertSame(3000, $this->dunningCaseService()->authorizeCollection(Scenario::command('b', 'case-b'))->amountMinor);
    }

    public function test_failed_execution_cas_rolls_back_the_collection_permit(): void
    {
        $this->dunningCaseService()->authorizeCollection(Scenario::command());
        $this->application()->make(RootPaymentCommands::class)->admit(Fixture::scope(), $this->commandFromOutbox())->orThrow();
        $owner = $this->owner();
        $owner->unprepared("CREATE FUNCTION public.billium_test_p73_refuse() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'");
        $owner->unprepared("CREATE TRIGGER billium_test_p73_refuse BEFORE UPDATE ON billium_execution_attempt FOR EACH ROW WHEN (OLD.state='admitted' AND NEW.state='dispatching') EXECUTE FUNCTION billium_test_p73_refuse()");
        try {
            $this->assertRefused(ProblemCode::RecoveryRequired, fn () => $this->dispatch());
            self::assertSame(0, $this->rows('billium_collection_dispatch_permit'));
            self::assertSame(1, $this->rows('billium_collection_step', "status = 'authorized'"));
            self::assertNull($this->value('SELECT collection_dispatch_permit_ref FROM billium_execution_attempt'));
            self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.call'));
        } finally {
            $owner->unprepared('DROP TRIGGER billium_test_p73_refuse ON billium_execution_attempt');
            $owner->unprepared('DROP FUNCTION public.billium_test_p73_refuse()');
        }
    }

    private function dispatch(): void
    {
        $this->application()->make(AttemptWorker::class)
            ->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'))->orThrow();
    }

    private function revoke(string $ref, TombstoneKind $kind): void
    {
        $command = new RevokeConsentCommand(
            commandKey: 'revoke', scope: $this->collectionScope(), consentRef: 'consent-1', tombstoneRef: 'revoke-a',
            sourceRevision: 2, cancellationGeneration: $kind === TombstoneKind::HostCancelled ? 1 : 0,
            reason: 'customer requested stop', evidenceDigest: self::digest(), tombstoneKind: $kind,
            targetAuthorizationRef: $ref, targetBusinessIntentRef: 'intent-a',
        );
        $receipt = $this->consentService()->revokeOrCancel($command);
        self::assertSame($receipt->tombstoneRef, $this->consentService()->revokeOrCancel($command)->tombstoneRef);
    }
}
