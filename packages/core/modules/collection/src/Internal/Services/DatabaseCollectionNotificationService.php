<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\CollectionNotificationPort;
use Billium\Collection\Contracts\CollectionNotificationService;
use Billium\Collection\Data\CollectionNotificationMessage;
use Billium\Collection\Data\CollectionNotificationView;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\EnqueueCollectionNotification;
use Billium\Collection\Enums\NotificationStatus;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseCollectionInterventionQueries;
use Billium\Collection\Internal\Support\PanCvvGuard;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use PDO;
use stdClass;
use Throwable;

#[Internal]
final readonly class DatabaseCollectionNotificationService implements CollectionNotificationService
{
    public const int NOTIFICATION_LOCK_SEGMENT = 4;

    /** @var list<string> */
    private const array COLLECTION_MODULES = ['platform', 'ledger', 'execution', 'collection'];

    /** @param Closure(): CollectionNotificationPort $notificationPortResolver */
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
        private Closure $notificationPortResolver,
    ) {}

    #[Override]
    public function enqueue(EnqueueCollectionNotification $command): CollectionNotificationView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');
        if ($command->stepRef !== null) {
            RowCast::bounded($command->stepRef, 'stepRef');
        }
        RowCast::bounded($command->templateRef, 'templateRef');
        RowCast::bounded($command->templateRevision, 'templateRevision');
        PanCvvGuard::assertOpaqueReference($command->recipientRef, 'recipientRef');
        PanCvvGuard::assertCleanPayload($command->payload);

        $payloadJson = CanonicalJson::encode($command->payload);
        $payloadDigest = CanonicalJson::digest($command->payload);
        $commandDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'stepRef' => $command->stepRef,
            'templateRef' => $command->templateRef,
            'templateRevision' => $command->templateRevision,
            'channel' => $command->channel->value,
            'recipientRef' => $command->recipientRef,
            'payloadDigest' => $payloadDigest,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.notification.enqueue.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $commandDigest,
                commitKind: 'collection.notification.enqueue',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $payloadJson, $payloadDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: DatabaseDunningCaseService::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before notification enqueue lock');
                }

                $stepId = null;
                $stepNo = 0;
                if ($command->stepRef !== null) {
                    $stepRow = $db->table('billium_collection_step')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('dunning_case_id', $caseId)
                        ->where('step_ref', $command->stepRef)
                        ->first();
                    if ($stepRow === null) {
                        throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.step_not_found');
                    }
                    $stepId = RowCast::integer($stepRow->id, 'id');
                    $stepNo = RowCast::integer($stepRow->step_no, 'step_no');
                }

                $messageId = self::messageId(
                    $caller->sellerRef,
                    $caller->environment,
                    $command->caseRef,
                    $stepNo,
                    $command->templateRef,
                    $command->templateRevision,
                    $command->channel->value,
                );

                $existing = $db->table('billium_collection_notification')
                    ->where('dunning_case_id', $caseId)
                    ->where('step_no', $stepNo)
                    ->where('template_ref', $command->templateRef)
                    ->where('template_revision', $command->templateRevision)
                    ->where('recipient_channel', $command->channel->value)
                    ->first();

                if ($existing !== null) {
                    if (
                        RowCast::str($existing->payload_digest, 'payload_digest') === $payloadDigest
                        && RowCast::str($existing->recipient_ref, 'recipient_ref') === $command->recipientRef
                    ) {
                        return new CollectionReceipt('collection.notification.enqueued', [
                            'notificationId' => RowCast::integer($existing->id, 'id'),
                            'messageId' => RowCast::str($existing->message_id, 'message_id'),
                        ]);
                    }

                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'billium.collection.notification_conflict',
                    );
                }

                $utc = new DateTimeZone('UTC');
                $enqueuedAt = ($command->enqueuedAt ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
                $now = new DateTimeImmutable('now', $utc);

                $inserted = $db->selectOne(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_notification (
                        seller_ref, environment, message_id,
                        dunning_case_id, case_ref, step_id, step_ref, step_no,
                        template_ref, template_revision, recipient_channel, recipient_ref,
                        payload, payload_digest, status, notification_revision, delivery_attempts,
                        transport_ack_ref, transport_acked_at, last_delivery_error, last_attempt_at,
                        enqueued_at, updated_at
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?::jsonb, ?, 'pending', 1, 0,
                        NULL, NULL, NULL, NULL,
                        ?, ?
                    )
                    ON CONFLICT (dunning_case_id, step_no, template_ref, template_revision, recipient_channel) DO NOTHING
                    RETURNING id
                    SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $messageId,
                        $caseId,
                        $command->caseRef,
                        $stepId,
                        $command->stepRef,
                        $stepNo,
                        $command->templateRef,
                        $command->templateRevision,
                        $command->channel->value,
                        $command->recipientRef,
                        $payloadJson,
                        $payloadDigest,
                        RowCast::formatUtc($enqueuedAt),
                        RowCast::formatUtc($now),
                    ],
                );

                if (! $inserted instanceof stdClass) {
                    $raced = $db->table('billium_collection_notification')
                        ->where('dunning_case_id', $caseId)
                        ->where('step_no', $stepNo)
                        ->where('template_ref', $command->templateRef)
                        ->where('template_revision', $command->templateRevision)
                        ->where('recipient_channel', $command->channel->value)
                        ->first() ?? throw new LogicException('Notification row vanished after conflict');

                    if (
                        RowCast::str($raced->payload_digest, 'payload_digest') !== $payloadDigest
                        || RowCast::str($raced->recipient_ref, 'recipient_ref') !== $command->recipientRef
                    ) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.notification_conflict',
                        );
                    }
                    $notificationId = RowCast::integer($raced->id, 'id');
                } else {
                    $notificationId = RowCast::integer($inserted->id, 'id');
                }

                return new CollectionReceipt('collection.notification.enqueued', [
                    'notificationId' => $notificationId,
                    'messageId' => $messageId,
                ]);
            },
        )->orThrow();

        $notificationId = RowCast::integer($outcome->body['notificationId'] ?? null, 'notificationId');

        return $this->loadById($caller->sellerRef, $caller->environment, $notificationId);
    }

    #[Override]
    public function dispatch(
        CollectionScope $scope,
        string $messageId,
        string $deliveryCommandKey,
        ?DateTimeImmutable $attemptedAt = null,
    ): CollectionNotificationView {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($messageId, 'messageId');
        RowCast::bounded($deliveryCommandKey, 'deliveryCommandKey');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_notification')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('message_id', $messageId)
            ->first();

        if ($row === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.notification_not_found');
        }

        $current = DatabaseCollectionInterventionQueries::hydrateNotification($row);
        if ($current->status === NotificationStatus::Delivered) {
            return $current;
        }

        $utc = new DateTimeZone('UTC');
        $attemptInstant = ($attemptedAt ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);

        $message = new CollectionNotificationMessage(
            messageId: $current->messageId,
            sellerRef: $current->sellerRef,
            environment: $current->environment,
            caseRef: $current->caseRef,
            stepRef: $current->stepRef,
            stepNo: $current->stepNo,
            templateRef: $current->templateRef,
            templateRevision: $current->templateRevision,
            channel: $current->channel,
            recipientRef: $current->recipientRef,
            payload: $current->payload,
            payloadDigest: $current->payloadDigest,
            attemptNo: $current->deliveryAttempts + 1,
        );

        // Host notification transport MUST execute strictly outside every database transaction.
        $this->assertOutsideTransaction();
        $port = ($this->notificationPortResolver)();
        $ack = null;
        $deliveryError = null;
        try {
            $ack = $port->deliver($message);
        } catch (ProblemException $problem) {
            if ($problem->problem === ProblemCode::LookupUnavailable) {
                throw $problem;
            }
            $deliveryError = substr($problem->detail !== '' ? $problem->detail : $problem->problem->value, 0, 500);
        } catch (Throwable $error) {
            $deliveryError = substr($error->getMessage() !== '' ? $error->getMessage() : $error::class, 0, 500);
        }

        if ($ack !== null) {
            if ($ack->messageId !== $current->messageId) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'billium.collection.transport_ack_message_mismatch',
                );
            }
            if (! $ack->transportDeliveryOnly) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'billium.collection.transport_ack_must_not_claim_payment_outcome',
                );
            }
            RowCast::bounded($ack->transportAckRef, 'transportAckRef');
        }

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'messageId' => $current->messageId,
            'expectedRevision' => $current->notificationRevision,
            'delivered' => $ack !== null,
            'transportAckRef' => $ack?->transportAckRef,
            'deliveryError' => $deliveryError,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.notification.dispatch.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $deliveryCommandKey,
                digest: $digest,
                commitKind: 'collection.notification.dispatch',
            ),
            body: function (TransactionContext $context) use ($caller, $current, $ack, $deliveryError, $attemptInstant): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_notification',
                    [$current->id],
                    scope: LockScope::Seller,
                    segment: self::NOTIFICATION_LOCK_SEGMENT,
                ));
                if ($locked !== [$current->id]) {
                    throw new LockPlanChanged('Collection notification vanished before lock');
                }

                $freshRow = $db->table('billium_collection_notification')
                    ->where('id', $current->id)
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->first() ?? throw new LockPlanChanged('Collection notification missing after lock');
                $fresh = DatabaseCollectionInterventionQueries::hydrateNotification($freshRow);

                if ($fresh->status === NotificationStatus::Delivered) {
                    return new CollectionReceipt('collection.notification.dispatched', [
                        'notificationId' => $fresh->id,
                        'status' => $fresh->status->value,
                    ]);
                }

                $nowUtc = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $attemptUtc = RowCast::formatUtc($attemptInstant);

                if ($ack !== null) {
                    $updated = $db->table('billium_collection_notification')
                        ->where('id', $fresh->id)
                        ->where('notification_revision', $fresh->notificationRevision)
                        ->update([
                            'status' => NotificationStatus::Delivered->value,
                            'notification_revision' => $fresh->notificationRevision + 1,
                            'delivery_attempts' => $fresh->deliveryAttempts + 1,
                            'transport_ack_ref' => $ack->transportAckRef,
                            'transport_acked_at' => RowCast::formatUtc($ack->acknowledgedAt),
                            'last_delivery_error' => null,
                            'last_attempt_at' => $attemptUtc,
                            'updated_at' => $nowUtc,
                        ]);
                } else {
                    $updated = $db->table('billium_collection_notification')
                        ->where('id', $fresh->id)
                        ->where('notification_revision', $fresh->notificationRevision)
                        ->update([
                            'status' => NotificationStatus::FailedDelivery->value,
                            'notification_revision' => $fresh->notificationRevision + 1,
                            'delivery_attempts' => $fresh->deliveryAttempts + 1,
                            'last_delivery_error' => $deliveryError ?? 'delivery_failed',
                            'last_attempt_at' => $attemptUtc,
                            'updated_at' => $nowUtc,
                        ]);
                }

                if ($updated !== 1) {
                    throw new LockPlanChanged('Collection notification dispatch CAS failed');
                }

                return new CollectionReceipt('collection.notification.dispatched', [
                    'notificationId' => $fresh->id,
                    'status' => $ack !== null ? NotificationStatus::Delivered->value : NotificationStatus::FailedDelivery->value,
                ]);
            },
        )->orThrow();

        $notificationId = RowCast::integer($outcome->body['notificationId'] ?? null, 'notificationId');

        return $this->loadById($caller->sellerRef, $caller->environment, $notificationId);
    }

    private function loadById(string $sellerRef, string $environment, int $notificationId): CollectionNotificationView
    {
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_notification')
            ->where('seller_ref', $sellerRef)
            ->where('environment', $environment)
            ->where('id', $notificationId)
            ->first() ?? throw new LogicException('Collection notification missing after commit');

        return DatabaseCollectionInterventionQueries::hydrateNotification($row);
    }

    private static function messageId(
        string $sellerRef,
        string $environment,
        string $caseRef,
        int $stepNo,
        string $templateRef,
        string $templateRevision,
        string $channel,
    ): string {
        return 'col-notif:'.hash('sha256', CanonicalJson::encode([
            $sellerRef,
            $environment,
            $caseRef,
            $stepNo,
            $templateRef,
            $templateRevision,
            $channel,
        ]));
    }

    private function assertOutsideTransaction(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            $writePdo = $connection->getRawPdo();
            $readPdo = $connection->getRawReadPdo();
            if (
                $connection->transactionLevel() !== 0
                || ($writePdo instanceof PDO && $writePdo->inTransaction())
                || ($readPdo instanceof PDO && $readPdo->inTransaction())
            ) {
                throw new LogicException('Collection notification operations must run outside every database transaction');
            }
        }
    }
}
