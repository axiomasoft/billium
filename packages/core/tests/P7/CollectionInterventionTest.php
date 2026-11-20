<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionManualInterventionService;
use Billium\Collection\Contracts\CollectionNotificationPort;
use Billium\Collection\Data\ApplyManualIntervention;
use Billium\Collection\Data\CollectionHostStatusSnapshot;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\EnqueueCollectionNotification;
use Billium\Collection\Data\EvaluateHostSignals;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Data\RecordOutcomeSignal;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionSignalKind;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\HostSnapshotFreshness;
use Billium\Collection\Enums\ManualOverrideAction;
use Billium\Collection\Enums\NotificationChannel;
use Billium\Collection\Enums\NotificationStatus;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Internal\Work\CollectionRecoveryWorker;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\P7\Support\CollectionAdmissionScenario as Scenario;
use Billium\Core\Tests\P7\Support\ExecutableCollectionTestCase;
use Billium\Core\Tests\P7\Support\ManualInterventionRaceScenario;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\ObservationApplier;
use Billium\Testkit\Coordinator\FakeProductCoordinator;
use Billium\Testkit\Coordinator\InMemoryCollectionNotificationPort;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('P7.6')]
final class CollectionInterventionTest extends ExecutableCollectionTestCase
{
    public function test_notification_outbox_dedupes_by_key_rejects_secrets_and_transport_ack_is_not_payment_success(): void
    {
        $scope = $this->collectionScope();
        $step = $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-step-notif-1',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: 'step-notif-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-notif-1',
            operationRef: 'op-notif-1',
            amountMinor: 5000,
            scheduledAt: new DateTimeImmutable('2026-11-20T01:00:00Z', new DateTimeZone('UTC')),
        ));

        // 1. Secret / PAN material in payload or recipientRef is rejected before persistence.
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-pan-1',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer:alpha',
            payload: ['card_number' => '4111111111111111'],
        )));
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-secret-1',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer:alpha',
            payload: ['nested' => ['api_key' => 'sk_live_secret_value']],
        )));
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-cvv-object',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer:alpha',
            payload: ['nested' => (object) ['cvv' => '123']],
        )));
        self::assertSame(0, $this->rows('billium_collection_notification'));

        // 2. Stable deduplication by (case, step, template, templateRevision, channel).
        $first = $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-enq-1',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer-channel://email/user-1',
            payload: ['dueMinor' => 5000, 'currency' => 'RUB', 'stepNo' => 1],
        ));
        self::assertSame(NotificationStatus::Pending, $first->status);
        self::assertSame(1, $first->notificationRevision);
        self::assertSame(0, $first->deliveryAttempts);
        self::assertFalse($first->paymentOutcomeChanged);

        // Re-enqueue identical key + payload with a different commandKey returns the exact same messageId.
        $replayed = $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-enq-1-dup',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer-channel://email/user-1',
            payload: ['currency' => 'RUB', 'stepNo' => 1, 'dueMinor' => 5000],
        ));
        self::assertSame($first->id, $replayed->id);
        self::assertSame($first->messageId, $replayed->messageId);
        self::assertSame(1, $this->rows('billium_collection_notification'));

        // Same key with changed payload -> IdempotencyConflict.
        $this->assertRefused(ProblemCode::IdempotencyConflict, fn () => $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-enq-1-conflict',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer-channel://email/user-1',
            payload: ['dueMinor' => 9999, 'currency' => 'RUB', 'stepNo' => 1],
        )));

        // Different channel or templateRevision creates a distinct durable notification.
        $sms = $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-enq-sms',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            templateRef: 'dunning_reminder',
            templateRevision: 'v1',
            channel: NotificationChannel::Sms,
            recipientRef: 'customer-channel://sms/user-1',
            payload: ['dueMinor' => 5000, 'currency' => 'RUB', 'stepNo' => 1],
        ));
        self::assertNotSame($first->messageId, $sms->messageId);
        self::assertSame(2, $this->rows('billium_collection_notification'));

        // 3. Unbound CollectionNotificationPort fails closed with LookupUnavailable.
        $this->assertRefused(
            ProblemCode::LookupUnavailable,
            fn () => $this->notificationService()->dispatch($scope, $first->messageId, 'notif-dispatch-unbound'),
        );

        // 4. Bind deterministic transport port: failed attempt -> retry -> delivered ACK.
        $port = new InMemoryCollectionNotificationPort;
        $this->application()->instance(CollectionNotificationPort::class, $port);

        $port->failNextWith('smtp_503_temporary_unavailable');
        $failed = $this->notificationService()->dispatch($scope, $first->messageId, 'notif-dispatch-1');
        self::assertSame(NotificationStatus::FailedDelivery, $failed->status);
        self::assertSame(1, $failed->deliveryAttempts);
        self::assertSame(2, $failed->notificationRevision);
        self::assertSame('delivery_failed', $failed->lastDeliveryError);
        self::assertNull($failed->transportAckRef);
        $deliveriesAfterFailure = $port->deliveryCount($first->messageId);
        $replayedFailure = $this->notificationService()->dispatch($scope, $first->messageId, 'notif-dispatch-1');
        self::assertSame(NotificationStatus::FailedDelivery, $replayedFailure->status);
        self::assertSame(1, $replayedFailure->deliveryAttempts);
        self::assertSame($deliveriesAfterFailure, $port->deliveryCount($first->messageId));

        // Port claiming transportDeliveryOnly = false is rejected cleanly.
        $port->setForceInvalidAckFlag(true);
        $this->assertRefused(
            ProblemCode::ValidationInvalid,
            fn () => $this->notificationService()->dispatch($scope, $first->messageId, 'notif-dispatch-invalid-ack'),
        );
        $port->setForceInvalidAckFlag(false);

        // Successful transport delivery ACK.
        $delivered = $this->notificationService()->dispatch($scope, $first->messageId, 'notif-dispatch-2');
        self::assertSame(NotificationStatus::Delivered, $delivered->status);
        self::assertSame(2, $delivered->deliveryAttempts);
        self::assertSame(3, $delivered->notificationRevision);
        self::assertNotNull($delivered->transportAckRef);
        self::assertNotNull($delivered->transportAckedAt);
        self::assertFalse($delivered->paymentOutcomeChanged);
        self::assertSame(0, $port->paymentOutcomeMutations());

        // Re-dispatching an already-delivered notification is an idempotent no-op (no extra transport calls).
        $beforeCount = $port->deliveryCount($first->messageId);
        $reDispatched = $this->notificationService()->dispatch($scope, $first->messageId, 'notif-dispatch-3');
        self::assertSame($delivered->transportAckRef, $reDispatched->transportAckRef);
        self::assertSame($beforeCount, $port->deliveryCount($first->messageId));

        // 5. CRITICAL INVARIANT: transport delivery ACK is NEVER business/payment success.
        $caseAfter = $this->dunningQueries()->findCase($scope, 'case-a');
        $stepAfter = $this->dunningQueries()->findStep($scope, $step->stepRef);
        self::assertNotNull($caseAfter);
        self::assertNotNull($stepAfter);
        self::assertSame(DunningCaseStatus::Active, $caseAfter->status);
        self::assertSame(5000, $caseAfter->currentOpenMinor);
        self::assertSame(CollectionStepStatus::Planned, $stepAfter->status);
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));

        // Query surface returns expected notification by key and list.
        $byKey = $this->interventionQueries()->findNotificationByKey(
            $scope,
            'case-a',
            1,
            'dunning_reminder',
            'v1',
            NotificationChannel::Email,
        );
        self::assertNotNull($byKey);
        self::assertSame($delivered->messageId, $byKey->messageId);
        self::assertCount(2, $this->interventionQueries()->listCaseNotifications($scope, 'case-a'));
    }

    public function test_notification_delivery_error_discards_transport_secrets(): void
    {
        $scope = $this->collectionScope();
        $notification = $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            'enqueue-error', $scope, 'case-a', null, 'dunning_reminder', 'v1', NotificationChannel::Email,
            'customer-channel://email/user-1', ['currency' => 'RUB'],
        ));
        $port = new InMemoryCollectionNotificationPort;
        $this->application()->instance(CollectionNotificationPort::class, $port);
        $port->failNextWith('transport rejected api_key=demo-secret');

        $failed = $this->notificationService()->dispatch($scope, $notification->messageId, 'dispatch-error');

        self::assertSame(NotificationStatus::FailedDelivery, $failed->status);
        self::assertSame('delivery_failed', $failed->lastDeliveryError);
        self::assertSame('delivery_failed', $this->value('SELECT last_delivery_error FROM billium_collection_notification'));
    }

    public function test_notification_omitted_payload_is_an_empty_json_object(): void
    {
        $command = new EnqueueCollectionNotification(
            'enqueue-empty', $this->collectionScope(), 'case-a', null, 'dunning_reminder', 'v1', NotificationChannel::Email,
            'customer-channel://email/user-1',
        );
        $service = $this->notificationService();
        $notification = $service->enqueue($command);

        self::assertSame('{}', $this->value('SELECT payload::text FROM billium_collection_notification'));
        self::assertSame([], $notification->payload);
        self::assertSame('sha256:'.hash('sha256', '{}'), $notification->payloadDigest);
        self::assertSame($notification->id, $service->enqueue($command)->id);
    }

    public function test_grace_and_read_only_host_signals_are_strictly_signal_only_without_host_resource_or_grant_mutation(): void
    {
        $scope = $this->collectionScope();
        $utc = new DateTimeZone('UTC');
        $openedAt = new DateTimeImmutable('2026-11-20T00:00:00Z', $utc);

        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            commandKey: 'policy-signals-v1',
            scope: $scope,
            policyRef: 'policy-signals',
            policyVersion: 1,
            allowedChannels: [CollectionChannel::SavedMethod],
            orderedSteps: [
                new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 0, sendNotification: true, notificationTemplateRef: 'tpl-step-1'),
            ],
            maxBusinessDeclineAttempts: 2,
            maxDeliveryRetries: 3,
            retryableDeclineCategories: [DeclineCategory::SoftDecline],
            recoveryHorizonSeconds: 86400,
            graceSignalAfterSeconds: 3600,
            readOnlySignalAfterSeconds: 7200,
        ));

        $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-case-signals',
            scope: $scope,
            caseRef: 'case-signals',
            billingAccountRef: 'account-a',
            obligationRef: 'obligation-a',
            collectionGeneration: 2,
            policyRef: 'policy-signals',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 5000,
            componentRef: 'component-a',
            openedAt: $openedAt,
        ));

        $coordinator = new FakeProductCoordinator;

        // Before grace threshold (30 minutes): 0 signals.
        $early = $this->signalService()->evaluatePolicySignals(new EvaluateHostSignals(
            commandKey: 'eval-signals-early',
            scope: $scope,
            caseRef: 'case-signals',
            asOf: $openedAt->modify('+1800 seconds'),
        ));
        self::assertSame([], $early);
        self::assertSame(0, $this->rows('billium_collection_host_signal'));

        // At grace threshold (1 hour): emits 1 signal-only grace fact.
        $atGrace = $this->signalService()->evaluatePolicySignals(new EvaluateHostSignals(
            commandKey: 'eval-signals-grace',
            scope: $scope,
            caseRef: 'case-signals',
            asOf: $openedAt->modify('+3600 seconds'),
        ));
        self::assertCount(1, $atGrace);
        self::assertSame(CollectionSignalKind::Grace, $atGrace[0]->signalKind);
        self::assertTrue($atGrace[0]->signalOnly);
        self::assertSame(false, $atGrace[0]->payload['hostResourceMutationPermitted'] ?? null);

        // Idempotent re-evaluation produces the same single grace signal (no duplicate row).
        $atGraceReplay = $this->signalService()->evaluatePolicySignals(new EvaluateHostSignals(
            commandKey: 'eval-signals-grace-replay',
            scope: $scope,
            caseRef: 'case-signals',
            asOf: $openedAt->modify('+4000 seconds'),
        ));
        self::assertCount(1, $atGraceReplay);
        self::assertSame($atGrace[0]->signalRef, $atGraceReplay[0]->signalRef);
        self::assertSame(1, $this->rows('billium_collection_host_signal'));

        self::assertTrue($coordinator->consumeSignal($atGrace[0]));
        self::assertFalse($coordinator->consumeSignal($atGrace[0])); // idempotent consumer
        self::assertSame('grace', $coordinator->accessPosture('case-signals'));

        // At read_only threshold (2 hours): emits read_only signal.
        $atReadOnly = $this->signalService()->evaluatePolicySignals(new EvaluateHostSignals(
            commandKey: 'eval-signals-readonly',
            scope: $scope,
            caseRef: 'case-signals',
            asOf: $openedAt->modify('+7200 seconds'),
        ));
        self::assertCount(2, $atReadOnly);
        self::assertSame(CollectionSignalKind::Grace, $atReadOnly[0]->signalKind);
        self::assertSame(CollectionSignalKind::ReadOnly, $atReadOnly[1]->signalKind);
        self::assertTrue($atReadOnly[1]->signalOnly);
        self::assertSame(2, $this->rows('billium_collection_host_signal'));

        $coordinator->consumeSignal($atReadOnly[1]);
        self::assertSame('read_only', $coordinator->accessPosture('case-signals'));

        // Strictly signal-only: zero resource deletions, zero direct grant mutations, zero GL writes, zero payments.
        self::assertSame(0, $coordinator->resourceDeletions());
        self::assertSame(0, $coordinator->directGrantMutationsFromSignals());
        self::assertSame(0, $coordinator->glWrites());
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));

        // Cannot record renewal_succeeded before verified settlement.
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->signalService()->recordOutcomeSignal(new RecordOutcomeSignal(
            commandKey: 'outcome-forged-renewal',
            scope: $scope,
            caseRef: 'case-signals',
            signalKind: CollectionSignalKind::RenewalSucceeded,
            signalDedupeKey: 'forged-1',
            effectiveAt: $openedAt->modify('+7300 seconds'),
        )));

        // Authoritative host status snapshot reflects active grace & read_only and forbids backup charges.
        $snapshot = $this->signalService()->readHostStatus($scope, 'case-signals', $openedAt->modify('+7200 seconds'));
        self::assertSame(HostSnapshotFreshness::Authoritative, $snapshot->freshness);
        self::assertTrue($snapshot->graceActive);
        self::assertTrue($snapshot->readOnlyActive);
        self::assertFalse($snapshot->paymentSettled);
        self::assertFalse($snapshot->backupChargeAllowed);
    }

    public function test_offline_host_reads_stale_or_unknown_provenance_and_never_initiates_backup_local_charge(): void
    {
        $scope = $this->collectionScope();
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('2026-11-20T12:00:00Z', $utc);
        $coordinator = new FakeProductCoordinator;

        // 1. Online read caches authoritative snapshot.
        $online = $coordinator->readCaseStatus($this->signalService(), $scope, 'case-a', $now);
        self::assertSame(HostSnapshotFreshness::Authoritative, $online->freshness);
        self::assertFalse($online->backupChargeAllowed);
        self::assertSame(DunningCaseStatus::Open, $online->caseStatus);

        // 2. Host goes offline -> cached case returns explicit Stale provenance.
        $coordinator->setBilliumReachable(false);
        $stale = $coordinator->readCaseStatus($this->signalService(), $scope, 'case-a', $now->modify('+5 minutes'));
        self::assertSame(HostSnapshotFreshness::Stale, $stale->freshness);
        self::assertSame('billium_unreachable', $stale->stalenessReason);
        self::assertFalse($stale->backupChargeAllowed);

        // 3. Uncached case while offline -> returns explicit Unknown provenance.
        $unknown = $coordinator->readCaseStatus($this->signalService(), $scope, 'case-never-seen', $now->modify('+5 minutes'));
        self::assertSame(HostSnapshotFreshness::Unknown, $unknown->freshness);
        self::assertSame('billium_unreachable', $unknown->stalenessReason);
        self::assertNull($unknown->caseStatus);
        self::assertFalse($unknown->backupChargeAllowed);

        // 4. Online read with exceeded maxStalenessSeconds also flags Stale provenance.
        $coordinator->setBilliumReachable(true);
        $agedOut = $this->signalService()->readHostStatus($scope, 'case-a', $now->modify('+10 hours'), maxStalenessSeconds: 60);
        self::assertSame(HostSnapshotFreshness::Stale, $agedOut->freshness);
        self::assertSame('snapshot_age_exceeded', $agedOut->stalenessReason);
        self::assertFalse($agedOut->backupChargeAllowed);

        // 5. Offline host NEVER initiates a backup local charge.
        $this->assertRefused(ProblemCode::OperationFinancialHold, static fn () => $coordinator->attemptOfflineBackupCharge($stale));
        $this->assertRefused(ProblemCode::OperationFinancialHold, static fn () => $coordinator->attemptOfflineBackupCharge($unknown));
        $this->assertRefused(ProblemCode::OperationFinancialHold, static fn () => new CollectionHostStatusSnapshot(
            sellerRef: 'seller-a',
            environment: 'test',
            caseRef: 'case-a',
            billingAccountRef: 'account-a',
            obligationRef: 'obligation-a',
            componentRef: 'component-a',
            freshness: HostSnapshotFreshness::Stale,
            caseStatus: DunningCaseStatus::Open,
            caseRevision: 1,
            latestStepStatus: null,
            paymentSettled: false,
            graceActive: false,
            readOnlyActive: false,
            backupChargeAllowed: true,
            signals: [],
            observedAt: $now,
            authoritativeAsOf: $now,
            stalenessReason: 'forced_test',
        ));

        self::assertSame(0, $coordinator->backupChargesInitiated());
        self::assertSame(2, $coordinator->refusedBackupCharges());
        self::assertSame(0, $this->rows('billium_collection_step'));
        self::assertSame(0, $this->rows('billium_execution_operation'));
        self::assertSame(0, UnknownResultTest::events($this->journal, 'provider.call'));
    }

    public function test_paid_plus_provision_failure_retries_provision_only_and_never_triggers_second_charge(): void
    {
        $scope = $this->collectionScope();
        $app = $this->application();

        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-prov',
            $scope,
            'agreement-a',
            'component-a',
            RecurringOwnerMode::BilliumScheduler,
            'schedule-a',
        ));

        // 1. Execute real end-to-end collection charge to verified settlement.
        $step = $this->dunningCaseService()->authorizeCollection(Scenario::command('prov'));
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), $this->commandFromOutbox())->orThrow();
        $app->make(AttemptWorker::class)->dispatch(
            Fixture::scope(),
            Refunds::attemptRef('root-prov'),
            'sender',
            new DateTimeImmutable('+1 minute'),
        )->orThrow();

        $workRef = FixtureValues::text($this->value(
            "SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint = 'root-prov' AND state = 'due'",
        ));
        $reduced = $app->make(EvidenceReducer::class)->reduce($workRef);
        self::assertCount(1, $reduced->observationCommandRefs);
        $fact = OutcomeDeliveryTest::attest($app, $this->journal, $reduced->observationCommandRefs[0]);
        $app->make(ObservationApplier::class)->apply(ObservationFactory::json($fact->raw));

        $workerResult = $app->make(CollectionRecoveryWorker::class)->run(Fixture::scope(), 'remote-billium');
        self::assertSame(1, $workerResult['done']);

        $settledCase = $this->dunningQueries()->findCase($scope, 'case-a');
        self::assertNotNull($settledCase);
        self::assertSame(DunningCaseStatus::Settled, $settledCase->status);
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(5000, $this->value('SELECT captured_minor FROM billium_payment'));

        // Record verified renewal_succeeded signal for host coordinator.
        $renewalSignal = $this->signalService()->recordOutcomeSignal(new RecordOutcomeSignal(
            commandKey: 'signal-renewal-ok',
            scope: $scope,
            caseRef: 'case-a',
            signalKind: CollectionSignalKind::RenewalSucceeded,
            signalDedupeKey: 'step:'.$step->stepRef,
            effectiveAt: new DateTimeImmutable('2026-11-20T12:05:00Z', new DateTimeZone('UTC')),
            payload: ['stepRef' => $step->stepRef, 'operationRef' => $step->operationRef],
        ));
        self::assertSame(CollectionSignalKind::RenewalSucceeded, $renewalSignal->signalKind);

        $coordinator = new FakeProductCoordinator;
        $coordinator->consumeSignal($renewalSignal);
        $status = $coordinator->readCaseStatus($this->signalService(), $scope, 'case-a', new DateTimeImmutable('2026-11-20T12:05:00Z', new DateTimeZone('UTC')));
        self::assertTrue($status->paymentSettled);

        // 2. Host provisioning fails on first attempt -> durable failure receipt, retryProvisionOnly = true, 0 extra charges!
        $coordinator->failNextProvisioning('entitlement_service_timeout');
        $attempt1 = $coordinator->provisionAfterSettledPayment('root-prov', 'component-a', 'grant-2026-11', $status->paymentSettled);
        self::assertFalse($attempt1->succeeded);
        self::assertSame('entitlement_service_timeout', $attempt1->failureReason);
        self::assertTrue($attempt1->retryProvisionOnly);
        self::assertFalse($attempt1->chargeTriggered);
        self::assertFalse($coordinator->hasActiveGrant('grant-2026-11'));

        // 3. Retry provisioning ONLY -> succeeds without triggering any second charge.
        $attempt2 = $coordinator->provisionAfterSettledPayment('root-prov', 'component-a', 'grant-2026-11', $status->paymentSettled);
        self::assertTrue($attempt2->succeeded);
        self::assertFalse($attempt2->chargeTriggered);
        self::assertTrue($coordinator->hasActiveGrant('grant-2026-11'));
        self::assertSame(2, $coordinator->provisioningAttemptCount('grant-2026-11'));

        // Replay is idempotent and returns same grant receipt.
        $attempt3 = $coordinator->provisionAfterSettledPayment('root-prov', 'component-a', 'grant-2026-11', $status->paymentSettled);
        self::assertSame($attempt2->receiptRef, $attempt3->receiptRef);
        self::assertSame(2, $coordinator->provisioningAttemptCount('grant-2026-11'));

        // Verify strictly ONE charge in provider journal, execution, and collection.
        self::assertSame(0, $coordinator->extraChargesFromProvisionRetry());
        self::assertSame(1, UnknownResultTest::events($this->journal, 'provider.call'));
        self::assertSame(1, $this->rows('billium_execution_operation'));
        self::assertSame(1, $this->rows('billium_collection_step'));
        self::assertSame(5000, $this->value('SELECT captured_minor FROM billium_payment'));
    }

    public function test_manual_override_enforces_privilege_reason_revision_proof_and_produces_audit_receipt(): void
    {
        $scope = $this->collectionScope();
        $proofDigest = self::digest('f');

        // 1. Caller WITHOUT seller-scoped manual override privilege is refused (AuthDenied).
        $this->assertRefused(ProblemCode::AuthDenied, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-no-priv',
            interventionRef: 'int-no-priv',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Operator pause requested',
            recoveryProofRef: 'ticket://ops/100',
            recoveryProofDigest: $proofDigest,
        )));
        self::assertSame(0, $this->rows('billium_collection_manual_intervention'));

        // 2. Seller B with Seller B's privilege CANNOT override Seller A's case (cross-seller isolation).
        $this->withOperator('seller-b', 'operator-b', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE.':seller-b']);
        $this->assertRefused(ProblemCode::AuthDenied, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-cross-seller',
            interventionRef: 'int-cross-seller',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Cross-seller attempt',
            recoveryProofRef: 'ticket://ops/101',
            recoveryProofDigest: $proofDigest,
        )));
        self::assertSame(0, $this->rows('billium_collection_manual_intervention'));

        // 3. Grant seller-scoped privilege for seller-a.
        $this->withOperator('seller-a', 'operator-a', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE.':seller-a']);

        // Empty/whitespace reason refused.
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-empty-reason',
            interventionRef: 'int-empty-reason',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: '   ',
            recoveryProofRef: 'ticket://ops/102',
            recoveryProofDigest: $proofDigest,
        )));

        // Missing recovery proof or invalid digest refused.
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-empty-proof',
            interventionRef: 'int-empty-proof',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Valid reason',
            recoveryProofRef: '   ',
            recoveryProofDigest: $proofDigest,
        )));
        $this->assertRefused(ProblemCode::ValidationInvalid, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-bad-digest',
            interventionRef: 'int-bad-digest',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Valid reason',
            recoveryProofRef: 'ticket://ops/103',
            recoveryProofDigest: 'not-a-sha256-digest',
        )));

        // Wrong expectedCaseRevision (CAS mismatch) refused with IdempotencyConflict.
        $this->assertRefused(ProblemCode::IdempotencyConflict, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-wrong-rev',
            interventionRef: 'int-wrong-rev',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 99,
            reason: 'Stale revision attempt',
            recoveryProofRef: 'ticket://ops/104',
            recoveryProofDigest: $proofDigest,
        )));

        // 4. Positive PauseCase -> ResumeCase -> RescheduleNextAttempt -> CancelCase lifecycle with audit receipts & signals.
        $pause = $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-pause-1',
            interventionRef: 'int-pause-1',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Customer requested temporary billing pause',
            recoveryProofRef: 'ticket://support/4201',
            recoveryProofDigest: $proofDigest,
        ));
        self::assertSame(ManualOverrideAction::PauseCase, $pause->action);
        self::assertSame('operator-a', $pause->actorRef);
        self::assertSame(CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE.':seller-a', $pause->privilegeRef);
        self::assertSame(1, $pause->fromCaseRevision);
        self::assertSame(2, $pause->toCaseRevision);
        self::assertSame(DunningCaseStatus::Open, $pause->beforeStatus);
        self::assertSame(DunningCaseStatus::Paused, $pause->afterStatus);
        self::assertNotSame($pause->beforeDigest, $pause->afterDigest);
        self::assertFalse($pause->paymentOutcomeMutated);
        self::assertFalse($pause->unknownOutcomeCleared);

        // Idempotent replay with identical interventionRef & terms returns same receipt; changed reason -> conflict.
        $pauseReplay = $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-pause-1-replay',
            interventionRef: 'int-pause-1',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Customer requested temporary billing pause',
            recoveryProofRef: 'ticket://support/4201',
            recoveryProofDigest: $proofDigest,
        ));
        self::assertSame($pause->id, $pauseReplay->id);
        self::assertSame($pause->receiptDigest, $pauseReplay->receiptDigest);

        $this->assertRefused(ProblemCode::IdempotencyConflict, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-pause-1-conflict',
            interventionRef: 'int-pause-1',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Altered reason',
            recoveryProofRef: 'ticket://support/4201',
            recoveryProofDigest: $proofDigest,
        )));

        $resumeAt = new DateTimeImmutable('+2 hours', new DateTimeZone('UTC'));
        $resume = $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-resume-1',
            interventionRef: 'int-resume-1',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::ResumeCase,
            expectedCaseRevision: 2,
            reason: 'Customer updated payment method',
            recoveryProofRef: 'ticket://support/4202',
            recoveryProofDigest: $proofDigest,
            nextAttemptAt: $resumeAt,
        ));
        self::assertSame(DunningCaseStatus::Paused, $resume->beforeStatus);
        self::assertSame(DunningCaseStatus::Open, $resume->afterStatus);
        self::assertSame(3, $resume->toCaseRevision);

        $rescheduleAt = new DateTimeImmutable('+3 hours', new DateTimeZone('UTC'));
        $reschedule = $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-resched-1',
            interventionRef: 'int-resched-1',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::RescheduleNextAttempt,
            expectedCaseRevision: 3,
            reason: 'Defer retry past bank maintenance window',
            recoveryProofRef: 'ticket://support/4203',
            recoveryProofDigest: $proofDigest,
            nextAttemptAt: $rescheduleAt,
        ));
        self::assertSame(DunningCaseStatus::Open, $reschedule->afterStatus);
        self::assertSame(4, $reschedule->toCaseRevision);

        $cancel = $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-cancel-1',
            interventionRef: 'int-cancel-1',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::CancelCase,
            expectedCaseRevision: 4,
            reason: 'Obligation resolved via approved credit memo',
            recoveryProofRef: 'ticket://support/4204',
            recoveryProofDigest: $proofDigest,
        ));
        self::assertSame(DunningCaseStatus::Cancelled, $cancel->afterStatus);
        self::assertSame(5, $cancel->toCaseRevision);

        // Terminal case refuses further manual overrides.
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-after-cancel',
            interventionRef: 'int-after-cancel',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::ResumeCase,
            expectedCaseRevision: 5,
            reason: 'Attempt to revive cancelled case',
            recoveryProofRef: 'ticket://support/4205',
            recoveryProofDigest: $proofDigest,
            nextAttemptAt: $rescheduleAt,
        )));

        self::assertCount(4, $this->interventionQueries()->listCaseManualInterventions($scope, 'case-a'));
        self::assertCount(4, $this->interventionQueries()->listCaseSignals($scope, 'case-a'));
        self::assertSame($cancel->receiptDigest, $this->interventionQueries()->findManualIntervention($scope, 'int-cancel-1')?->receiptDigest);
        self::assertSame(0, $this->rows('billium_payment'));
    }

    public function test_manual_override_cannot_forge_payment_success_or_clear_unknown_outcome(): void
    {
        $scope = $this->collectionScope();
        $proofDigest = self::digest('b');
        $this->withOperator('seller-a', 'operator-a', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE]);

        // 1. Explicit ForgePaymentSuccess and ClearUnknownOutcome actions are ALWAYS refused.
        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-forge-paid',
            interventionRef: 'int-forge-paid',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::ForgePaymentSuccess,
            expectedCaseRevision: 1,
            reason: 'Attempt to mark payment succeeded manually',
            recoveryProofRef: 'ticket://ops/999',
            recoveryProofDigest: $proofDigest,
        )));

        $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-clear-unknown-action',
            interventionRef: 'int-clear-unknown-action',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::ClearUnknownOutcome,
            expectedCaseRevision: 1,
            reason: 'Attempt to clear unknown outcome',
            recoveryProofRef: 'ticket://ops/1000',
            recoveryProofDigest: $proofDigest,
        )));

        // 2. The manual override grant does not authorize direct lifecycle changes.
        $plan = new PlanCollectionStep(
            commandKey: 'plan-unknown-step',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: 'step-unknown-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-unknown-1',
            operationRef: 'op-unknown-1',
            amountMinor: 5000,
            scheduledAt: new DateTimeImmutable('2026-11-20T01:00:00Z', new DateTimeZone('UTC')),
        );
        $this->assertRefused(ProblemCode::AuthDenied, fn () => $this->dunningCaseService()->planStep($plan));
        $this->withOperator('seller-a', 'host-integration', [self::DIRECT_STEP_LIFECYCLE]);
        $step = $this->dunningCaseService()->planStep($plan);
        $transition = new TransitionCollectionStep(
            commandKey: 'trans-unknown-1',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: $step->stepRef,
            targetStatus: CollectionStepStatus::Unknown,
            expectedStepRevision: $step->stepRevision,
            occurredAt: new DateTimeImmutable('2026-11-20T01:05:00Z', new DateTimeZone('UTC')),
            declineReason: 'psp_timeout_after_send',
        );
        $this->withOperator('seller-a', 'operator-a', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE]);
        $this->assertRefused(ProblemCode::AuthDenied, fn () => $this->dunningCaseService()->transitionStep($transition));
        $this->withOperator('seller-a', 'host-integration', [self::DIRECT_STEP_LIFECYCLE]);
        $unknownResult = $this->dunningCaseService()->transitionStep($transition);
        $this->withOperator('seller-a', 'operator-a', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE]);
        self::assertSame(DunningCaseStatus::ParkedUnknown, $unknownResult->case->status);
        self::assertSame(CollectionStepStatus::Unknown, $unknownResult->step->status);

        // Host status read on parked_unknown case also reports Unknown freshness and backupChargeAllowed = false.
        $unknownStatus = $this->signalService()->readHostStatus($scope, 'case-a');
        self::assertSame(HostSnapshotFreshness::Unknown, $unknownStatus->freshness);
        self::assertSame('collection_outcome_unknown', $unknownStatus->stalenessReason);
        self::assertFalse($unknownStatus->backupChargeAllowed);

        // Every manual override action against a parked_unknown case is refused without mutating case/step.
        foreach ([
            ManualOverrideAction::PauseCase,
            ManualOverrideAction::ResumeCase,
            ManualOverrideAction::RescheduleNextAttempt,
            ManualOverrideAction::CancelCase,
        ] as $i => $action) {
            $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
                commandKey: 'ov-parked-'.$i,
                interventionRef: 'int-parked-'.$i,
                scope: $scope,
                caseRef: 'case-a',
                action: $action,
                expectedCaseRevision: $unknownResult->case->caseRevision,
                reason: 'Attempt override on parked_unknown case',
                recoveryProofRef: 'ticket://ops/parked-'.$i,
                recoveryProofDigest: $proofDigest,
                nextAttemptAt: new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')),
            )));
        }

        // Verify case remains parked_unknown, step remains unknown, and 0 manual interventions recorded.
        self::assertSame(0, $this->rows('billium_collection_manual_intervention'));
        self::assertSame('parked_unknown', $this->value("SELECT status FROM billium_collection_dunning_case WHERE case_ref = 'case-a'"));
        self::assertSame('unknown', $this->value("SELECT status FROM billium_collection_step WHERE step_ref = 'step-unknown-1'"));
    }

    public function test_manual_override_forbids_pausing_or_cancelling_in_flight_authorized_step(): void
    {
        $scope = $this->collectionScope();
        $this->withOperator('seller-a', 'operator-a', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE]);

        $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            'owner-inflight',
            $scope,
            'agreement-a',
            'component-a',
            RecurringOwnerMode::BilliumScheduler,
            'schedule-a',
        ));
        $this->dunningCaseService()->authorizeCollection(Scenario::command('inflight'));
        $case = $this->dunningQueries()->findCase($scope, 'case-a');
        self::assertNotNull($case);

        foreach ([ManualOverrideAction::PauseCase, ManualOverrideAction::CancelCase] as $i => $action) {
            $this->assertRefused(ProblemCode::OperationFinancialHold, fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
                commandKey: 'ov-inflight-'.$i,
                interventionRef: 'int-inflight-'.$i,
                scope: $scope,
                caseRef: 'case-a',
                action: $action,
                expectedCaseRevision: $case->caseRevision,
                reason: 'Attempt override while authorized step is in flight',
                recoveryProofRef: 'ticket://ops/inflight-'.$i,
                recoveryProofDigest: self::digest('e'),
            )));
        }
        self::assertSame(0, $this->rows('billium_collection_manual_intervention'));
    }

    public function test_concurrent_manual_overrides_serialize_via_cas_revision(): void
    {
        $results = ConcurrencyStand::run(
            ManualInterventionRaceScenario::class,
            6,
            ['caseRef' => 'case-a', 'expectedCaseRevision' => 1],
        );

        $accepted = 0;
        $rejected = 0;
        foreach ($results as $entry) {
            self::assertArrayHasKey('ok', $entry, json_encode($entry, JSON_THROW_ON_ERROR));
            $payload = $entry['ok'];
            if (($payload['accepted'] ?? false) === true) {
                $accepted++;
            } else {
                $rejected++;
                self::assertSame(ProblemCode::IdempotencyConflict->value, $payload['problem'] ?? null);
            }
        }

        self::assertSame(1, $accepted);
        self::assertSame(5, $rejected);

        self::assertSame(1, $this->rows('billium_collection_manual_intervention'));
        self::assertSame(1, $this->rows('billium_collection_host_signal', "signal_kind = 'manual_intervention'"));
        self::assertSame('paused', $this->value("SELECT status FROM billium_collection_dunning_case WHERE case_ref = 'case-a'"));
        self::assertSame(2, $this->value("SELECT case_revision FROM billium_collection_dunning_case WHERE case_ref = 'case-a'"));
    }

    public function test_database_triggers_check_constraints_and_out_of_tx_guards_enforce_immutability(): void
    {
        $scope = $this->collectionScope();
        $this->withOperator('seller-a', 'operator-a', [CollectionManualInterventionService::PRIVILEGE_MANUAL_OVERRIDE]);

        $notif = $this->notificationService()->enqueue(new EnqueueCollectionNotification(
            commandKey: 'notif-db-guard',
            scope: $scope,
            caseRef: 'case-a',
            stepRef: null,
            templateRef: 'case_opened',
            templateRevision: 'v1',
            channel: NotificationChannel::Email,
            recipientRef: 'customer-channel://email/u1',
            payload: ['openMinor' => 5000],
        ));
        $port = new InMemoryCollectionNotificationPort;
        $this->application()->instance(CollectionNotificationPort::class, $port);
        $this->notificationService()->dispatch($scope, $notif->messageId, 'notif-db-guard-dispatch');

        $override = $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
            commandKey: 'ov-db-guard',
            interventionRef: 'int-db-guard',
            scope: $scope,
            caseRef: 'case-a',
            action: ManualOverrideAction::PauseCase,
            expectedCaseRevision: 1,
            reason: 'Guard verification pause',
            recoveryProofRef: 'ticket://ops/db-guard',
            recoveryProofDigest: self::digest('a'),
        ));

        $caseId = $this->value("SELECT id FROM billium_collection_dunning_case WHERE case_ref = 'case-a'");
        $owner = $this->owner();

        // Direct SQL tampering against triggers & CHECK constraints MUST fail.
        $forbiddenSql = [
            // Append-only host_signal cannot be updated or deleted.
            ["UPDATE public.billium_collection_host_signal SET signal_kind = 'grace' WHERE signal_ref = ?", [$override->signalRef], '55000'],
            ['DELETE FROM public.billium_collection_host_signal WHERE signal_ref = ?', [$override->signalRef], '55000'],
            // Append-only manual_intervention cannot be updated or deleted.
            ["UPDATE public.billium_collection_manual_intervention SET reason = 'tampered' WHERE intervention_ref = ?", [$override->interventionRef], '55000'],
            ['DELETE FROM public.billium_collection_manual_intervention WHERE intervention_ref = ?', [$override->interventionRef], '55000'],
            // Delivered notification cannot transition or be deleted.
            ["UPDATE public.billium_collection_notification SET status = 'pending', notification_revision = notification_revision + 1, transport_ack_ref = NULL, transport_acked_at = NULL WHERE message_id = ?", [$notif->messageId], '23514'],
            ['DELETE FROM public.billium_collection_notification WHERE message_id = ?', [$notif->messageId], '23514'],
            // SQL CHECK backstop forbids forging payment success or settling via manual_intervention.
            [
                "INSERT INTO public.billium_collection_manual_intervention (seller_ref, environment, intervention_ref, dunning_case_id, case_ref, action, actor_ref, privilege_ref, reason, recovery_proof_ref, recovery_proof_digest, from_case_revision, to_case_revision, before_status, after_status, before_digest, after_digest, receipt_digest, signal_ref, recorded_at) VALUES ('seller-a', 'test', 'sql-forge', ?, 'case-a', 'forge_payment_success', 'op', 'priv', 'bad', 'proof', ?, 2, 3, 'paused', 'settled', ?, ?, ?, ?, now())",
                [$caseId, self::digest('a'), self::digest('b'), self::digest('c'), self::digest('d'), $override->signalRef],
                '23514',
            ],
            // SQL CHECK backstop forbids raw PAN digit sequence in notification payload.
            [
                "INSERT INTO public.billium_collection_notification (seller_ref, environment, message_id, dunning_case_id, case_ref, step_no, template_ref, template_revision, recipient_channel, recipient_ref, payload, payload_digest, status, notification_revision, delivery_attempts, enqueued_at, updated_at) VALUES ('seller-a', 'test', 'sql-pan-msg', ?, 'case-a', 0, 'tpl', 'v1', 'sms', 'rcpt', '{\"note\":\"4111111111111111\"}'::jsonb, ?, 'pending', 1, 0, now(), now())",
                [$caseId, self::digest('e')],
                '23514',
            ],
        ];

        foreach ($forbiddenSql as [$sql, $bindings, $expectedSqlState]) {
            try {
                $owner->statement($sql, $bindings);
                self::fail('Expected SQLSTATE '.$expectedSqlState.' for: '.$sql);
            } catch (QueryException $exception) {
                self::assertSame($expectedSqlState, $exception->getCode(), $exception->getMessage());
            }
        }

        // Out-of-transaction guard rejects calls made inside an active database transaction.
        $db = $this->application()->make(DatabaseManager::class)->connection('pgsql');
        $db->beginTransaction();
        try {
            $calls = [
                fn () => $this->notificationService()->enqueue(new EnqueueCollectionNotification(
                    'tx-notif', $scope, 'case-a', null, 'tpl', 'v1', NotificationChannel::Email, 'rcpt',
                )),
                fn () => $this->notificationService()->dispatch($scope, $notif->messageId, 'tx-dispatch'),
                fn () => $this->signalService()->evaluatePolicySignals(new EvaluateHostSignals(
                    'tx-signal', $scope, 'case-a', new DateTimeImmutable,
                )),
                fn () => $this->manualInterventionService()->applyOverride(new ApplyManualIntervention(
                    'tx-override', 'tx-int', $scope, 'case-a', ManualOverrideAction::PauseCase, 2, 'reason', 'proof', self::digest('a'),
                )),
            ];
            foreach ($calls as $call) {
                try {
                    $call();
                    self::fail('Expected LogicException inside active transaction');
                } catch (LogicException $e) {
                    self::assertStringContainsString('outside every database transaction', $e->getMessage());
                }
            }
        } finally {
            $db->rollBack();
        }
    }

    /** @param list<string> $permissions */
    private function withOperator(string $sellerRef, string $actorRef, array $permissions): void
    {
        $this->application()->instance(CallerContext::class, new FixedCallerContext(new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: $actorRef,
            sourceAuthorities: ['remote-billium'],
            intentOwnerNamespaces: ['source-a'],
            permissions: $permissions,
        )));
    }
}
