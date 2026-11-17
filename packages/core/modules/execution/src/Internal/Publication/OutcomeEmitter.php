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
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\PublicationReceipt;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class OutcomeEmitter implements OutcomePublication
{
    public function __construct(private ExecutionStore $store, private FinancialSessionAdmission $admission, private PaymentObservationSink $commands,
        private Outbox $outbox, private ModuleRegistry $modules) {}

    public function emit(TransactionContext $ctx, MaterialChange $change): PublicationReceipt
    {
        $op = $change->after;
        $s = $change->observed;
        $scope = $op->binding->scope;
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
        $token = $this->admission->currentToken($scope->installationRef) ?? throw new LogicException('Current source process admission is required');
        $lineage = AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch());
        $part = $this->store->ensureObservationPartition($ctx, $scope, $op->binding->executionProfile, $lineage, $op->rootOperationRef);
        $sequence = $this->store->nextObservationSequence($ctx, $part->id);
        $event = (string) Str::ulid();
        $now = new DateTimeImmutable;
        $refund = $op->kind === EffectKind::Refund;
        $detailAmount = $refund ? ($s->operationAmount ?? throw new LogicException('Refund detail has no authenticated amount')) : null;
        $body = ['schema' => PaymentObservation::SCHEMA, 'eventId' => $event, 'sourceAuthority' => $part->sourceAuthority, 'sourceLineage' => $lineage,
            'partitionRef' => $part->partitionRef, 'sourceSequence' => $sequence, 'installationRef' => $scope->installationRef, 'environment' => $scope->environment,
            'sellerRef' => $scope->sellerRef, 'operationRef' => $op->operationRef, 'operationKind' => $refund ? 'refund' : 'charge',
            'businessIntentRef' => $op->binding->businessIntentRef, 'operationRevision' => $op->revision, 'state' => self::status($op, $s->normalizedStatus),
            'currency' => $s->currency, 'requested' => $op->requested->toWire(), 'authorizedTotal' => ($detailAmount ?? $s->authorizedTotal)->toWire(),
            'capturedTotal' => ($detailAmount ?? $s->capturedTotal)->toWire(), 'refundedTotal' => ($refund ? new Money(0, $op->requested->currency) : $s->refundedTotal)->toWire(),
            'providerAccountRef' => $op->binding->merchantAccountRef, 'evidenceRef' => $evidence, 'observedAt' => self::time($s->observedAt), 'recordedAt' => self::time($now)];
        if ($refund) {
            $body['originalPaymentRef'] = $op->rootOperationRef;
        }
        if ($op->operationRef === $s->operationRef && $s->providerOperationId !== null) {
            $body['providerOperationId'] = $s->providerOperationId;
        }
        if ($change->discrepancyRef !== null) {
            $body['discrepancy'] = ['discrepancyRef' => $change->discrepancyRef, 'kind' => $change->reason, 'revision' => $op->revision,
                'acceptedTotals' => ['authorizedTotal' => $change->capacity->authorizedLimit->toWire(), 'capturedTotal' => $change->capacity->confirmedCapture->toWire(),
                    'refundedTotal' => $change->capacity->confirmedRefund->toWire()], 'verifiedExternalFacts' => [['evidenceRef' => $evidence]]];
        }
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
        if (! in_array($failed->kind, [EffectKind::Capture, EffectKind::VoidAuthorization], true)) {
            throw new LogicException('No certified effect outcome contract for this operation kind');
        }
        $event = $this->effect($ctx, $failed, new Money(0, $failed->requested->currency), 'failed-terminal', $proof->certificate->certificateRef,
            $proof->certificate->coverageThrough);

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
