<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Queries;

use Billium\Collection\Contracts\CollectionInterventionQueries;
use Billium\Collection\Data\CollectionHostSignalView;
use Billium\Collection\Data\CollectionNotificationView;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ManualInterventionReceipt;
use Billium\Collection\Enums\CollectionSignalKind;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\ManualOverrideAction;
use Billium\Collection\Enums\NotificationChannel;
use Billium\Collection\Enums\NotificationStatus;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseCollectionInterventionQueries implements CollectionInterventionQueries
{
    public function __construct(
        private DatabaseManager $databases,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function findNotification(CollectionScope $scope, string $messageId): ?CollectionNotificationView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($messageId, 'messageId');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_notification')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('message_id', $messageId)
            ->first();

        return $row === null ? null : self::hydrateNotification($row);
    }

    #[Override]
    public function findNotificationByKey(
        CollectionScope $scope,
        string $caseRef,
        int $stepNo,
        string $templateRef,
        string $templateRevision,
        NotificationChannel $channel,
    ): ?CollectionNotificationView {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');
        RowCast::bounded($templateRef, 'templateRef');
        RowCast::bounded($templateRevision, 'templateRevision');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_notification')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('case_ref', $caseRef)
            ->where('step_no', $stepNo)
            ->where('template_ref', $templateRef)
            ->where('template_revision', $templateRevision)
            ->where('recipient_channel', $channel->value)
            ->first();

        return $row === null ? null : self::hydrateNotification($row);
    }

    #[Override]
    public function listCaseNotifications(CollectionScope $scope, string $caseRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_notification')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('case_ref', $caseRef)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrateNotification($row);
        }

        return $out;
    }

    #[Override]
    public function listCaseSignals(CollectionScope $scope, string $caseRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_host_signal')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('case_ref', $caseRef)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrateSignal($row);
        }

        return $out;
    }

    #[Override]
    public function findManualIntervention(CollectionScope $scope, string $interventionRef): ?ManualInterventionReceipt
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($interventionRef, 'interventionRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_manual_intervention')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('intervention_ref', $interventionRef)
            ->first();

        return $row === null ? null : self::hydrateManualIntervention($row);
    }

    #[Override]
    public function listCaseManualInterventions(CollectionScope $scope, string $caseRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_manual_intervention')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('case_ref', $caseRef)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrateManualIntervention($row);
        }

        return $out;
    }

    public static function hydrateNotification(stdClass $row): CollectionNotificationView
    {
        return new CollectionNotificationView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            messageId: RowCast::str($row->message_id, 'message_id'),
            dunningCaseId: RowCast::integer($row->dunning_case_id, 'dunning_case_id'),
            caseRef: RowCast::str($row->case_ref, 'case_ref'),
            stepId: RowCast::optInt($row->step_id, 'step_id'),
            stepRef: RowCast::optStr($row->step_ref, 'step_ref'),
            stepNo: RowCast::integer($row->step_no, 'step_no'),
            templateRef: RowCast::str($row->template_ref, 'template_ref'),
            templateRevision: RowCast::str($row->template_revision, 'template_revision'),
            channel: NotificationChannel::from(RowCast::str($row->recipient_channel, 'recipient_channel')),
            recipientRef: RowCast::str($row->recipient_ref, 'recipient_ref'),
            payload: RowCast::jsonObject($row->payload, 'payload'),
            payloadDigest: RowCast::str($row->payload_digest, 'payload_digest'),
            status: NotificationStatus::from(RowCast::str($row->status, 'status')),
            notificationRevision: RowCast::integer($row->notification_revision, 'notification_revision'),
            deliveryAttempts: RowCast::integer($row->delivery_attempts, 'delivery_attempts'),
            transportAckRef: RowCast::optStr($row->transport_ack_ref, 'transport_ack_ref'),
            transportAckedAt: RowCast::optUtcDateTime($row->transport_acked_at, 'transport_acked_at'),
            lastDeliveryError: RowCast::optStr($row->last_delivery_error, 'last_delivery_error'),
            lastAttemptAt: RowCast::optUtcDateTime($row->last_attempt_at, 'last_attempt_at'),
            enqueuedAt: RowCast::utcDateTime($row->enqueued_at, 'enqueued_at'),
            updatedAt: RowCast::utcDateTime($row->updated_at, 'updated_at'),
            paymentOutcomeChanged: false,
        );
    }

    public static function hydrateSignal(stdClass $row): CollectionHostSignalView
    {
        return new CollectionHostSignalView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            signalRef: RowCast::str($row->signal_ref, 'signal_ref'),
            dunningCaseId: RowCast::integer($row->dunning_case_id, 'dunning_case_id'),
            caseRef: RowCast::str($row->case_ref, 'case_ref'),
            billingAccountRef: RowCast::str($row->billing_account_ref, 'billing_account_ref'),
            obligationRef: RowCast::str($row->obligation_ref, 'obligation_ref'),
            componentRef: RowCast::optStr($row->component_ref, 'component_ref'),
            signalKind: CollectionSignalKind::from(RowCast::str($row->signal_kind, 'signal_kind')),
            signalDedupeKey: RowCast::str($row->signal_dedupe_key, 'signal_dedupe_key'),
            caseRevision: RowCast::integer($row->case_revision, 'case_revision'),
            policyRef: RowCast::str($row->policy_ref, 'policy_ref'),
            policyVersion: RowCast::integer($row->policy_version, 'policy_version'),
            signalOnly: (bool) $row->signal_only,
            payload: RowCast::jsonObject($row->payload, 'payload'),
            signalDigest: RowCast::str($row->signal_digest, 'signal_digest'),
            effectiveAt: RowCast::utcDateTime($row->effective_at, 'effective_at'),
            recordedAt: RowCast::utcDateTime($row->recorded_at, 'recorded_at'),
        );
    }

    public static function hydrateManualIntervention(stdClass $row): ManualInterventionReceipt
    {
        return new ManualInterventionReceipt(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            interventionRef: RowCast::str($row->intervention_ref, 'intervention_ref'),
            dunningCaseId: RowCast::integer($row->dunning_case_id, 'dunning_case_id'),
            caseRef: RowCast::str($row->case_ref, 'case_ref'),
            stepId: RowCast::optInt($row->step_id, 'step_id'),
            stepRef: RowCast::optStr($row->step_ref, 'step_ref'),
            action: ManualOverrideAction::from(RowCast::str($row->action, 'action')),
            actorRef: RowCast::str($row->actor_ref, 'actor_ref'),
            privilegeRef: RowCast::str($row->privilege_ref, 'privilege_ref'),
            reason: RowCast::str($row->reason, 'reason'),
            recoveryProofRef: RowCast::str($row->recovery_proof_ref, 'recovery_proof_ref'),
            recoveryProofDigest: RowCast::str($row->recovery_proof_digest, 'recovery_proof_digest'),
            fromCaseRevision: RowCast::integer($row->from_case_revision, 'from_case_revision'),
            toCaseRevision: RowCast::integer($row->to_case_revision, 'to_case_revision'),
            beforeStatus: DunningCaseStatus::from(RowCast::str($row->before_status, 'before_status')),
            afterStatus: DunningCaseStatus::from(RowCast::str($row->after_status, 'after_status')),
            beforeDigest: RowCast::str($row->before_digest, 'before_digest'),
            afterDigest: RowCast::str($row->after_digest, 'after_digest'),
            receiptDigest: RowCast::str($row->receipt_digest, 'receipt_digest'),
            signalRef: RowCast::str($row->signal_ref, 'signal_ref'),
            recordedAt: RowCast::utcDateTime($row->recorded_at, 'recorded_at'),
            paymentOutcomeMutated: false,
            unknownOutcomeCleared: false,
        );
    }
}
