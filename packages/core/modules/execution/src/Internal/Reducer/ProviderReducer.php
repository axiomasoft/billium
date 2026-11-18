<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Reducer;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ProviderWorkInput;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Authority\AbsenceProofRegistry;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Capacity\ExecutionCapacity;
use Billium\Execution\Internal\Custody\VerifiedDelivery;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Publication\OutcomeEmitter;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Contracts\Installation\Installation;
use DateTimeImmutable;
use Exception;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final class ProviderReducer implements EvidenceReducer
{
    public function __construct(private readonly ExecutionStore $store, private readonly OperationRunner $runner, private readonly CallerContext $callers,
        private readonly FinancialSessionAdmission $admission, private readonly Installation $installation,
        private readonly MerchantRegistry $merchants, private readonly ReduceMaterial $material, private readonly OutcomeEmitter $publication, private readonly AbsenceProofRegistry $absence,
        private readonly VerifiedReduction $verified) {}

    public function reduce(string $workRef): ReductionResult
    {
        $input = $this->store->providerWork($workRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown provider processing work');
        $evidence = $input->evidence;
        $scope = $evidence->scope;
        $hint = $input->operationHint;
        $op = $hint === null ? null : $this->store->operation($scope, $hint);
        if (in_array($input->state, ['quarantined', 'parked', 'processed'], true)) {
            return new ReductionResult(match ($input->state) {
                'processed' => ReductionDisposition::Duplicate, default => ReductionDisposition::Quarantined
            },
                $hint ?? $evidence->evidenceRef, $op?->revision, [$evidence->evidenceRef], [], [], $input->state === 'quarantined' ? $input->resultRef : null);
        }
        $claim = null;
        $this->runner->run(self::raw($scope), function (TransactionContext $ctx) use ($workRef, $input, &$claim): ExecutionReceipt {
            $claim = $this->store->claimProviderWork($ctx, $workRef, 'reduce:'.bin2hex(random_bytes(16)), new DateTimeImmutable('+1 minute'));
            if ($claim === null) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider work is leased or not due');
            }

            return self::receipt($claim, $input->operationHint ?? $claim->evidenceRef, 'leased');
        })->orThrow();
        if (! $claim instanceof ProviderWorkClaim) {
            throw new LogicException('Provider work lease was not committed');
        }
        if ($op === null) {
            return $this->rawFinish($scope, $claim, $hint, 'quarantined', 'unmatched_signed_operation');
        }
        if (! in_array($claim->handlerContractVersion, [$op->binding->driverContractVersion, 'execution.provider.v1', 'execution.transport.v1'], true)) {
            return $this->rawFinish($scope, $claim, $hint, 'parked', 'unsupported_handler_version');
        }
        $certificate = null;
        $delivery = null;
        try {
            if ($evidence->handlerContractVersion === 'execution.transport.v1') {
                $certificate = $this->certified($input, $op);
            } else {
                $delivery = $this->verified->authenticate($op, $evidence);
            }
        } catch (Exception) {
            return $this->rawFinish($scope, $claim, $hint, 'quarantined', 'provider_verification_failed');
        }
        try {
            return $this->financial($op, $claim, $delivery, $certificate);
        } catch (Throwable $failure) {
            // Losing financial admission or a transient database fault cannot discard a verified external effect.
            $park = $claim->attemptCount >= 8;
            $this->rawFinish($scope, $claim, $hint, $park ? 'parked' : 'due', 'financial_processing_deferred');
            throw $failure;
        }
    }

    private function certified(ProviderWorkInput $input, OperationRecord $operation): VerifiedAbsence
    {
        $evidence = $input->evidence;
        $payload = $evidence->sanitizedPayload;
        if (($payload['source'] ?? null) !== 'verified_absence' || ($payload['operationRef'] ?? null) !== $operation->operationRef
            || ! is_array($payload['absenceCertificate'] ?? null) || ($payload['verifiedAbsence'] ?? null) !== true) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Transport result is not a verified absence');
        }
        $attempt = $this->store->attemptForOperation($evidence->scope, $operation->operationRef) ?? throw new LogicException('Original absence attempt is missing');
        $certificate = ExecutionCodec::decode(AbsenceCertificate::class, ExecutionCodec::encode($payload['absenceCertificate']));
        $merchant = $this->store->merchantRevision($evidence->scope, $operation->binding->merchantRevisionRef);
        $capability = $this->merchants->capabilities($merchant->capabilitySnapshotRef);

        return $this->absence->verify($certificate, $operation, $attempt, $capability);
    }

    private function financial(OperationRecord $op, ProviderWorkClaim $claim, ?VerifiedDelivery $delivery, ?VerifiedAbsence $certificate): ReductionResult
    {
        $scope = $op->binding->scope;
        $plan = $this->store->plan($scope, $op->operationRef, true);
        $session = $this->installation->currentSession($scope->installationRef);
        $token = $this->admission->currentToken($scope->installationRef) ?? throw new ProblemException(ProblemCode::AuthDenied, 'Process token is unavailable');
        $mode = $session?->state === 'recovery' ? FinancialMode::Recovery : FinancialMode::Active;
        $spec = new OperationSpec('execution.reduce_provider_fact', $scope->sellerRef, $scope->environment, $mode, ModuleOperationClass::ObservedFactRecording,
            $plan->participatingModuleIds, commitKind: 'execution.reduce_provider_fact', financialScope: PaymentIdentity::select($this->callers->current(), $scope, $op->binding->executionProfile));
        $result = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($op, $scope, $claim, $delivery, $certificate, $plan, $token, &$result): ExecutionReceipt {
            if ($delivery !== null) {
                $this->store->ensureObservationPartition($ctx, $scope, $op->binding->executionProfile, AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch()), $op->rootOperationRef);
            }
            // Discovery can pick up the newly inserted partition and new siblings before acquiring any domain row lock.
            $current = $this->store->plan($scope, $op->operationRef, true, $ctx);
            if ($current->participatingModuleIds !== $plan->participatingModuleIds || $current->rootOperationRef !== $plan->rootOperationRef) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider reduction module or root ownership changed');
            }
            $this->store->lock($ctx, $current);
            if ($delivery !== null) {
                $result = $this->verified->run($ctx, $claim, $delivery, fn (): ReductionResult => $this->reduceIn($ctx, $claim, $delivery->snapshot));
            } elseif ($certificate !== null) {
                $attempt = $this->store->attemptForOperation($scope, $op->operationRef, $ctx) ?? throw new LogicException('Original attempt is absent');
                $reservation = $this->store->reservationForOperation($ctx, $op->operationRef);
                $released = $reservation?->state === ReservationState::Held ? ExecutionCapacity::withoutHold($this->store->capacity($ctx, $op->rootOperationRef), $reservation) : null;
                $this->store->applyCertifiedAbsence($ctx, $attempt, $claim, $certificate, $released);
                $failed = $this->store->operation($scope, $op->operationRef, $ctx) ?? throw new LogicException('Certified operation is absent');
                $events = $this->publication->emitCertifiedNoEffect($ctx, $failed, $certificate)->eventIds;
                $result = new ReductionResult(ReductionDisposition::Applied, $op->operationRef, $failed->revision, [$claim->evidenceRef], [], $events, null);
            } else {
                throw new LogicException('Provider work has no qualified decision');
            }
            $quarantined = $result->disposition === ReductionDisposition::Quarantined;
            if ($certificate === null && ! $this->store->finishProviderWork($ctx, $claim, $quarantined ? 'quarantined' : 'processed', $result->discrepancyRef ?? $claim->evidenceRef,
                $quarantined ? 'provider_material_conflict' : null, null)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Provider work fence changed before commit');
            }

            return self::receipt($claim, $op->operationRef, $result->disposition->value);
        })->orThrow();

        return $result ?? throw new LogicException('No committed provider reduction result');
    }

    public function reduceIn(TransactionContext $ctx, ProviderWorkClaim $claim, MaterialSnapshot $snapshot): ReductionResult
    {
        $this->runner->assertCurrent($ctx, 'execution');

        return $this->material->apply($ctx, $this->verified->verifiedIn($ctx, $claim, $snapshot), $claim);
    }

    private function rawFinish(ExecutionScope $scope, ProviderWorkClaim $claim, ?string $op, string $state, string $reason): ReductionResult
    {
        $this->runner->run(self::raw($scope), function (TransactionContext $ctx) use ($claim, $op, $state, $reason): ExecutionReceipt {
            $due = $state === 'due' ? new DateTimeImmutable('+'.min(3600, 5 * 2 ** min(9, $claim->attemptCount)).' seconds') : null;
            if (! $this->store->finishProviderWork($ctx, $claim, $state, $claim->evidenceRef, $reason, $due)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Processing claim changed before its recorded disposition');
            }

            return self::receipt($claim, $op ?? $claim->evidenceRef, $state);
        })->orThrow();

        return new ReductionResult($op === null ? ReductionDisposition::Unmatched : ReductionDisposition::Quarantined, $op ?? $claim->evidenceRef, null, [$claim->evidenceRef], [], [], null);
    }

    private static function raw(ExecutionScope $scope): OperationSpec
    {
        return new OperationSpec('execution.provider_work', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody, ModuleOperationClass::CallbackCustody,
            ['execution', 'platform'], financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace));
    }

    private static function receipt(ProviderWorkClaim $claim, string $op, string $state): ExecutionReceipt
    {
        return new ExecutionReceipt($claim->workRef, $claim->workRef, $op, $claim->evidenceRef, $state, false, [$claim->evidenceRef]);
    }
}
