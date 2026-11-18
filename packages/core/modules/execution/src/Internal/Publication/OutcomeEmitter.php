<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Publication;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\OutcomePublication;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\PublicationReceipt;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class OutcomeEmitter implements OutcomePublication
{
    public function __construct(private ExecutionStore $store, private FinancialSessionAdmission $admission, private PaymentObservationSink $commands,
        private Outbox $outbox, private ModuleRegistry $modules, private DatabaseManager $databases) {}

    public function emit(TransactionContext $ctx, MaterialChange $change): PublicationReceipt
    {
        $op = $change->after;
        $s = $change->observed;
        $this->store->assertMaterialRevision($ctx, $change);
        if ($change->observations !== [] || $change->publicFacts !== []) {
            throw new LogicException('Publication requires the committed-in-root operation change');
        }
        $evidence = $s->evidenceRefs[0] ?? throw new LogicException('Observation needs an accepted provider evidence reference');
        if (in_array($op->kind, [EffectKind::Capture, EffectKind::VoidAuthorization], true)) {
            $amount = $s->operationAmount ?? throw new LogicException('Child effect has no authenticated amount');
            $event = $this->effect($ctx, $op, $amount, self::status($op, $s->normalizedStatus), $evidence, $s->observedAt);

            return new PublicationReceipt([], [], [$event], []);
        }
        $refund = $op->kind === EffectKind::Refund;
        $detailAmount = $refund ? ($s->operationAmount ?? throw new LogicException('Refund detail has no authenticated amount')) : null;
        $body = ['operationKind' => $refund ? 'refund' : 'charge', 'state' => self::status($op, $s->normalizedStatus),
            'currency' => $s->currency, 'authorizedTotal' => ($detailAmount ?? $s->authorizedTotal)->toWire(),
            'capturedTotal' => ($detailAmount ?? $s->capturedTotal)->toWire(), 'refundedTotal' => ($refund ? new Money(0, $op->requested->currency) : $s->refundedTotal)->toWire(),
            'evidenceRef' => $evidence, 'observedAt' => self::time($s->observedAt)];
        if ($refund) {
            $body['originalPaymentRef'] = $op->rootOperationRef;
        }
        if ($op->operationRef === $s->operationRef && $s->providerOperationId !== null) {
            $body['providerOperationId'] = $s->providerOperationId;
        }
        if ($change->discrepancyRef !== null) {
            $cause = $refund ? $evidence : $this->rootDiscrepancyEvidence($ctx, $change);
            $body['discrepancy'] = ['discrepancyRef' => $change->discrepancyRef, 'kind' => $change->reason, 'revision' => $op->revision,
                'acceptedTotals' => ['authorizedTotal' => $change->capacity->authorizedLimit->toWire(), 'capturedTotal' => $change->capacity->confirmedCapture->toWire(),
                    'refundedTotal' => $change->capacity->confirmedRefund->toWire()], 'verifiedExternalFacts' => [['evidenceRef' => $cause]]];
        }

        return $this->observation($ctx, $op, $body);
    }

    private function rootDiscrepancyEvidence(TransactionContext $ctx, MaterialChange $change): string
    {
        $root = $change->after;
        $scope = $root->binding->scope;
        // The root's cumulative evidence and the child fact that put it on hold may be different.
        $row = $this->databases->connection($ctx->connectionName())->table('billium_execution_discrepancy')
            ->where(['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment,
                'root_operation_ref' => $root->operationRef, 'discrepancy_ref' => $change->discrepancyRef, 'kind' => 'provider_material_conflict'])
            ->where('exposure->reason', $change->reason)->first(['operation_ref', 'observed', 'evidence_refs->0 as evidence_ref']);
        if ($row === null || ! is_string($row->operation_ref) || ! is_string($row->evidence_ref) || ! is_string($row->observed)) {
            throw new LogicException('Held root requires its persisted material discrepancy');
        }
        $subject = $this->store->operation($scope, $row->operation_ref, $ctx);
        $fact = $this->store->factForEvidence($ctx, $row->evidence_ref, $row->operation_ref);
        if ($subject === null || $fact === null || $subject->rootOperationRef !== $root->operationRef || $subject->kind !== $fact->snapshot->kind
            || $fact->snapshot->operationRef !== $subject->operationRef || $fact->snapshot->bindingDigest !== ExecutionCodec::bindingDigest($root->binding)
            || $fact->snapshot->merchantAccountRef !== $root->binding->merchantAccountRef || ($fact->snapshot->evidenceRefs[0] ?? null) !== $row->evidence_ref
            || ExecutionCodec::encode($fact->snapshot) !== ExecutionCodec::encode(ExecutionCodec::map($row->observed))) {
            throw new LogicException('Root discrepancy differs from its original qualified operation fact');
        }

        return $row->evidence_ref;
    }

    public function emitQuarantineHold(TransactionContext $ctx, ProviderWorkClaim $claim, MaterialSnapshot $conflict, string $ref, string $reason): PublicationReceipt
    {
        $root = $this->store->recordQuarantineHold($ctx, $claim, $conflict, $ref, $reason);
        if ($root === null) {
            return new PublicationReceipt([], [], [], []);
        }
        $accepted = $this->store->latestRootObservation($ctx, $root->operationRef);
        $capacity = $this->store->capacity($ctx, $root->operationRef);
        $totals = ['authorizedTotal' => $capacity->authorizedLimit->toWire(), 'capturedTotal' => $capacity->confirmedCapture->toWire(),
            'refundedTotal' => $capacity->confirmedRefund->toWire()];
        // The disputed observation is custody evidence only. No amount or terminal status in it becomes a provider fact.
        $body = ['operationKind' => 'charge', 'state' => self::status($root, $accepted->normalizedStatus ?? 'unknown'), 'currency' => $root->requested->currency->code,
            'authorizedTotal' => $accepted?->authorizedTotal->toWire() ?? $totals['authorizedTotal'],
            'capturedTotal' => $accepted?->capturedTotal->toWire() ?? $totals['capturedTotal'],
            'refundedTotal' => $accepted?->refundedTotal->toWire() ?? $totals['refundedTotal'],
            'evidenceRef' => $accepted->evidenceRefs[0] ?? $ref, 'observedAt' => self::time($accepted->observedAt ?? new DateTimeImmutable),
            'discrepancy' => ['discrepancyRef' => $ref, 'kind' => $reason, 'revision' => $root->revision, 'acceptedTotals' => $totals,
                'verifiedExternalFacts' => [['evidenceRef' => $claim->evidenceRef, 'operationRef' => $conflict->operationRef, 'disposition' => 'quarantined',
                    'totalsBasis' => $accepted === null ? 'local_confirmed_capacity' : 'last_accepted_provider_snapshot']]]];
        if ($accepted?->operationRef === $root->operationRef && $accepted->providerOperationId !== null) {
            $body['providerOperationId'] = $accepted->providerOperationId;
        }

        return $this->observation($ctx, $root, $body);
    }

    /** @param array<string,mixed> $body */
    private function observation(TransactionContext $ctx, OperationRecord $op, array $body): PublicationReceipt
    {
        $scope = $op->binding->scope;
        $token = $this->admission->currentToken($scope->installationRef) ?? throw new LogicException('Current source process admission is required');
        $lineage = AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch());
        $part = $this->store->ensureObservationPartition($ctx, $scope, $op->binding->executionProfile, $lineage, $op->rootOperationRef);
        $sequence = $this->store->nextObservationSequence($ctx, $part->id);
        $event = (string) Str::ulid();
        $body += ['schema' => PaymentObservation::SCHEMA, 'eventId' => $event, 'sourceAuthority' => $part->sourceAuthority, 'sourceLineage' => $lineage,
            'partitionRef' => $part->partitionRef, 'sourceSequence' => $sequence, 'installationRef' => $scope->installationRef, 'environment' => $scope->environment,
            'sellerRef' => $scope->sellerRef, 'operationRef' => $op->operationRef, 'businessIntentRef' => $op->binding->businessIntentRef,
            'operationRevision' => $op->revision, 'requested' => $op->requested->toWire(), 'providerAccountRef' => $op->binding->merchantAccountRef,
            'recordedAt' => self::time(new DateTimeImmutable)];
        $codec = new Codec;
        $body['contentDigest'] = $codec->digest($body, 'contentDigest');
        $observation = $codec->decode($codec->canonicalize($body));
        if (! $observation instanceof PaymentObservation) {
            throw new LogicException('Invalid observation contract');
        }
        $command = 'observe:'.substr(PaymentIdentity::digest(['partition' => $part->partitionRef, 'sequence' => (string) $sequence,
            'operation' => $op->operationRef, 'revision' => (string) $op->revision]), 7);
        $row = $this->commands->record($ctx, $command, $op->rootOperationRef, $observation);
        $this->store->appendEmission($ctx, new ObservationEmission('emission:'.$event, $scope, $part->sourceAuthority, $lineage, $part->partitionRef,
            $sequence, $op->operationRef, $op->revision, $command, $event, $observation->contentDigest, $observation, $row));

        return new PublicationReceipt([$command], [$row], [], [$sequence]);
    }

    public function emitCertifiedNoEffect(TransactionContext $ctx, OperationRecord $failed, VerifiedAbsence $proof): PublicationReceipt
    {
        $this->store->assertCertifiedDecision($ctx, $failed, $proof);
        $certificate = $proof->certificate;
        if (in_array($failed->kind, [EffectKind::Capture, EffectKind::VoidAuthorization], true)) {
            $event = $this->effect($ctx, $failed, new Money(0, $failed->requested->currency), 'failed-terminal', $certificate->certificateRef,
                $certificate->coverageThrough);
        } else {
            $event = $this->publicEvent($ctx, $failed, 'payment.certified_no_effect.v1', $certificate->coverageThrough, [
                'operationRef' => $failed->operationRef, 'rootOperationRef' => $failed->rootOperationRef, 'operationKind' => $failed->kind->value,
                'revision' => $failed->revision, 'status' => 'failed-terminal', 'requested' => $failed->requested->toWire(),
                'bindingRevision' => $failed->binding->merchantRevisionRef, 'merchantAccountRef' => $failed->binding->merchantAccountRef,
                'providerReference' => $certificate->providerReference, 'certificateRef' => $certificate->certificateRef,
                'certificateDigest' => ExecutionCodec::digest($certificate), 'coverageThrough' => self::time($certificate->coverageThrough),
            ]);
        }

        return new PublicationReceipt([], [], [$event], []);
    }

    private function effect(TransactionContext $ctx, OperationRecord $op, Money $amount, string $status, string $evidence, DateTimeImmutable $at): string
    {
        $scope = $op->binding->scope;
        $type = 'payment.effect.outcome.v1';
        $body = ['schema' => $type, 'installationRef' => $scope->installationRef, 'environment' => $scope->environment, 'sellerRef' => $scope->sellerRef,
            'operationRef' => $op->operationRef, 'originalOperationRef' => $op->rootOperationRef, 'operationKind' => $op->kind->value, 'revision' => $op->revision,
            'status' => $status, 'money' => $op->requested->toWire(), 'effectTotal' => $amount->amount(), 'bindingRevision' => $op->binding->merchantRevisionRef,
            'merchantAccountRef' => $op->binding->merchantAccountRef, 'providerEvidenceRef' => $evidence, 'occurredAt' => self::time($at)];
        $codec = new Codec;
        $body['digest'] = $codec->digest($body);
        $codec->decode($codec->canonicalize($body));

        return $this->publicEvent($ctx, $op, $type, $at, $body);
    }

    /** @param array<string,mixed> $body */
    private function publicEvent(TransactionContext $ctx, OperationRecord $op, string $type, DateTimeImmutable $at, array $body): string
    {
        foreach ($this->modules->descriptor('execution')->publicEvents ?? [] as $declaration) {
            if ($declaration->eventType === $type) {
                $event = (string) Str::ulid();
                $this->outbox->record($ctx, new RegisteredPublicEvent(EventContractRegistration::from('execution', $declaration),
                    new OutboxEvent($event, $type, $declaration->envelopeSchema, $op->operationRef, $op->revision, $at, $body)));

                return $event;
            }
        }
        throw new LogicException('Execution effect outcome contract is not registered');
    }

    private static function status(OperationRecord $op, string $observed): string
    {
        return match ($op->state) {
            'failed' => 'failed-terminal', 'cancelled' => 'cancelled', 'admitted', 'pending' => 'pending', 'unknown' => 'accepted-unknown',
            'held' => match ($observed) {
                'succeeded' => 'succeeded', 'declined', 'failed', 'no_effect' => 'failed-terminal', default => 'accepted-unknown'
            },
            default => 'succeeded',
        };
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
