<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Queries;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\ExecutionStatus;
use Billium\Contracts\Data\Envelopes\FindResult;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Ledger\Contracts\ObservationCheckpointQueries;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseExecutionQueries implements ExecutionQueries
{
    public function __construct(
        private ExecutionReads $reads,
        private ExecutionStore $store,
        private MerchantRegistry $merchants,
        private IdempotencyReceiptQueries $receipts,
        private ObservationCheckpointQueries $checkpoints,
    ) {}

    public function find(ExecutionScope $scope, string $operationRef): FindResult
    {
        $view = $this->view($scope, $operationRef);
        $operation = $view->operation;
        $body = ['schema' => FindResult::SCHEMA, 'operationRef' => $operation->operationRef];
        if ($view->absenceCertificate !== null) {
            $body += ['status' => 'absent', 'absenceCertificate' => $view->absenceCertificate];
        } else {
            $body += ['status' => $view->status, 'revision' => $operation->revision, 'digest' => $operation->economicDigest,
                'capturedTotal' => $view->capacity->confirmedCapture->toWire(), 'refundedTotal' => $view->capacity->confirmedRefund->toWire(),
                'reservedRefundTotal' => $view->capacity->heldRefund->toWire()];
        }
        $decoded = (new Codec)->decode(json_encode($body, JSON_THROW_ON_ERROR));

        return $decoded instanceof FindResult ? $decoded : throw new LogicException('Execution find response does not match its contract');
    }

    public function status(ExecutionScope $scope, string $operationRef): ExecutionStatus
    {
        $view = $this->view($scope, $operationRef);
        $operation = $view->operation;
        $merchant = $this->merchants->historical($scope, $operation->rootOperationRef);
        $capability = $this->merchants->capabilities($merchant->capabilitySnapshotRef);
        $delivery = $this->delivery($view);
        $body = ['schema' => ExecutionStatus::SCHEMA, 'installationRef' => $scope->installationRef,
            'environment' => $scope->environment, 'sellerRef' => $scope->sellerRef, 'operationRef' => $operation->operationRef,
            'rootOperationRef' => $operation->rootOperationRef, 'bindingRevision' => $operation->binding->merchantRevisionRef,
            'merchantAccountRef' => $operation->binding->merchantAccountRef, 'capabilityVersion' => $capability->capabilityVersion,
            'driverContractVersion' => $operation->binding->driverContractVersion, 'operationKind' => $operation->kind->value,
            'revision' => $operation->revision, 'status' => $view->status, 'requested' => $operation->requested->toWire(),
            'capacity' => self::capacityWire($view->capacity), 'delivery' => $delivery['delivery'], 'recovery' => $delivery['recovery']];
        $decoded = (new Codec)->decode(json_encode($body, JSON_THROW_ON_ERROR));

        return $decoded instanceof ExecutionStatus ? $decoded : throw new LogicException('Execution status response does not match its contract');
    }

    public function receipt(ExecutionScope $scope, string $receiptRef): ExecutionReceipt
    {
        ExecutionReads::validate($receiptRef);
        foreach ($this->reads->sources($scope) as $source) {
            $stored = $this->receipts->byReceiptRef($this->reads->select($scope, $source), 'execution.operation', $receiptRef);
            if ($stored === null) {
                continue;
            }
            $body = $stored->outcome->body;
            $ref = ExecutionCodec::text($body['operationRef'] ?? null);
            $this->reads->operation($scope, $ref, $source);
            $evidence = $body['evidenceRefs'] ?? null;
            $replayed = $body['replayed'] ?? null;
            if (! is_array($evidence) || ! is_bool($replayed) || ($body['receiptRef'] ?? null) !== $receiptRef) {
                throw new LogicException('Unreadable execution receipt');
            }

            return new ExecutionReceipt($receiptRef, ExecutionCodec::text($body['commandKey'] ?? null), $ref,
                ExecutionCodec::text($body['semanticDigest'] ?? null), ExecutionCodec::text($body['status'] ?? null), $replayed, $evidence);
        }

        throw ExecutionReads::unknown();
    }

    private function view(ExecutionScope $scope, string $operationRef): ExecutionView
    {
        return $this->reads->snapshot(function (Connection $db) use ($scope, $operationRef): ExecutionView {
            $operation = $this->reads->operation($scope, $operationRef);
            $capacity = ExecutionReads::table($db, 'capacity', $scope)->where('root_operation_ref', $operation->rootOperationRef)->first()
                ?? throw new LogicException('Stored execution operation has no capacity row');
            $amount = static fn (string $field): Money => new Money(self::integer($capacity->{$field}), $operation->requested->currency);
            $snapshot = new CapacitySnapshot($operation->rootOperationRef, self::integer($capacity->version), $amount('authorized_limit_minor'),
                $amount('confirmed_capture_minor'), $amount('confirmed_refund_minor'), $amount('released_authorization_minor'),
                $amount('held_capture_minor'), $amount('held_void_minor'), $amount('held_refund_minor'), $capacity->financial_hold === true);
            $attempt = $this->store->attemptForOperation($scope, $operationRef);
            $revision = ExecutionReads::table($db, 'operation_revision', $scope)->where('operation_ref', $operationRef)
                ->where('revision', $operation->revision)->first(['observed', 'receipt_ref']);
            $observed = $revision === null ? [] : ExecutionCodec::map(ExecutionCodec::text($revision->observed));
            $absence = ($observed['schema'] ?? null) === 'billium.execution.certified-no-effect.v1' && $attempt !== null
                ? self::certificate($db, $operation, $attempt) : null;

            return new ExecutionView($operation, $snapshot, self::wireStatus($operation, $attempt),
                array_key_exists('normalizedStatus', $observed) || $absence !== null, $absence,
                $revision === null ? [] : self::observations($db, $operation, ExecutionCodec::text($revision->receipt_ref), $observed));
        });
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return array<string, PaymentObservation>
     */
    private static function observations(Connection $db, OperationRecord $operation, string $revisionReceipt, array $observed): array
    {
        $scope = $operation->binding->scope;
        $observations = [];
        $rows = ExecutionReads::table($db, 'observation_emission', $scope)->where('source_authority', $operation->binding->executionProfile);
        $refs = [[$operation->operationRef, $operation->revision]];
        $materialDigest = $observed['materialDigest'] ?? null;
        if ($operation->operationRef !== $operation->rootOperationRef && is_string($materialDigest)) {
            // A child's ledger projection is the root observation produced by the same factual reduction.
            $rootRevision = ExecutionReads::table($db, 'operation_revision', $scope)->where('operation_ref', $operation->rootOperationRef)
                ->where('receipt_ref', $revisionReceipt)->where('observed->operationRef', $operation->operationRef)
                ->where('observed->materialDigest', $materialDigest)->orderByDesc('revision')->value('revision');
            if ($rootRevision !== null) {
                array_unshift($refs, [$operation->rootOperationRef, self::integer($rootRevision)]);
            }
        }
        foreach ($refs as [$ref, $number]) {
            $row = (clone $rows)->where('operation_ref', $ref)->where('operation_revision', $number)
                ->orderByDesc('id')->first(['command_ref', 'payload']);
            if ($row !== null) {
                $payload = (new Codec)->decode(ExecutionCodec::text($row->payload));
                if (! $payload instanceof PaymentObservation) {
                    throw new LogicException('Stored execution observation does not match its schema');
                }
                $observations[ExecutionCodec::text($row->command_ref)] = $payload;
            }
        }

        return $observations;
    }

    private static function certificate(Connection $db, OperationRecord $operation, AttemptRecord $attempt): ?stdClass
    {
        $scope = $operation->binding->scope;
        $verified = ExecutionReads::table($db, 'certified_absence', $scope)->where('attempt_ref', $attempt->attemptRef)
            ->first(['certificate_digest', 'evidence_ref']);
        if ($verified === null) {
            return null;
        }
        $payload = ExecutionReads::table($db, 'provider_evidence', $scope)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('evidence_ref', $verified->evidence_ref)->where('signature_verdict', 'verified_no_effect')->value('sanitized_payload');
        $decoded = json_decode(ExecutionCodec::text($payload), false, 64, JSON_THROW_ON_ERROR);
        $certificate = $decoded instanceof stdClass ? ($decoded->absenceCertificate ?? null) : null;
        if (! $certificate instanceof stdClass || ExecutionCodec::digest($certificate) !== $verified->certificate_digest) {
            throw new LogicException('Retained certified-absence decision has no matching certificate');
        }

        return $certificate;
    }

    private static function wireStatus(OperationRecord $operation, ?AttemptRecord $attempt): string
    {
        return match ($operation->state) {
            'failed' => 'failed-terminal',
            'cancelled' => 'cancelled',
            'admitted' => match ($attempt?->state) {
                AttemptState::Dispatching, AttemptState::Unknown => 'accepted-unknown',
                default => 'pending',
            },
            'pending' => 'pending',
            'unknown', 'recovery_required', 'held' => 'accepted-unknown',
            'succeeded', 'authorized', 'partially_captured', 'captured', 'refunded', 'voided', 'expired' => 'succeeded',
            default => throw new LogicException('Unknown persisted execution state'),
        };
    }

    /** @return array<string, array{amount:string,currency:string}> */
    private static function capacityWire(CapacitySnapshot $capacity): array
    {
        $currency = $capacity->authorizedLimit->currency;

        return ['authorizedLimit' => $capacity->authorizedLimit->toWire(), 'confirmedCapture' => $capacity->confirmedCapture->toWire(),
            'confirmedRefund' => $capacity->confirmedRefund->toWire(), 'releasedAuthorization' => $capacity->releasedAuthorization->toWire(),
            'heldCapture' => $capacity->heldCapture->toWire(), 'heldVoid' => $capacity->heldVoid->toWire(), 'heldRefund' => $capacity->heldRefund->toWire(),
            'captureAvailable' => new Money(CapacityAmounts::available($capacity, EffectKind::Capture), $currency)->toWire(),
            'refundAvailable' => new Money(CapacityAmounts::available($capacity, EffectKind::Refund), $currency)->toWire()];
    }

    /** @return array{delivery:array<string,mixed>,recovery:array<string,mixed>} */
    private function delivery(ExecutionView $view): array
    {
        $op = $view->operation;
        $source = $this->reads->select($op->binding->scope, $op->binding->executionProfile);
        $projected = $completed = $view->observations !== [];
        $revision = null;
        $reasons = [];
        $recoveryRequired = $op->state === 'recovery_required';
        $held = $view->capacity->financialHold || $op->state === 'held';
        foreach ($view->observations as $observation) {
            $checkpoint = $this->checkpoints->checkpoint($source, $observation);
            $mode = $checkpoint?->allocationReceipt?->mode();
            $projected = $projected && $checkpoint?->projectionApplied === true;
            $completed = $completed && in_array($mode, ['assign', 'refund_detail'], true);
            $recoveryRequired = $recoveryRequired || $checkpoint?->recoveryRequired === true;
            $held = $held || $mode === 'hold';
            if ($mode === 'assign') {
                $revision = max($revision ?? 0, $observation->operationRevision);
            }
            if ($checkpoint?->pendingReason !== null) {
                $reasons[] = $checkpoint->pendingReason;
            }
        }
        $pending = in_array($view->status, ['pending', 'accepted-unknown'], true) || ($view->observations !== [] && ! $completed);
        $recovery = match (true) {
            $recoveryRequired => 'recovery_required',
            $held => 'held_discrepancy',
            $pending => 'pending',
            default => 'current',
        };
        $delivery = ['commandAccepted' => true, 'providerOutcomeRecorded' => $view->providerOutcomeRecorded,
            'projectionApplied' => $projected, 'allocationApplied' => $completed && $revision !== null, 'observationCommandRefs' => array_keys($view->observations)];
        if ($revision !== null) {
            $delivery['lastAppliedObservationRevision'] = $revision;
        }

        return ['delivery' => $delivery, 'recovery' => ['status' => $recovery, 'reasonCodes' => array_values(array_unique($reasons))]];
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint');
    }
}
