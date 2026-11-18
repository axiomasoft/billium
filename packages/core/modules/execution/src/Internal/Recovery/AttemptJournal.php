<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Recovery;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class AttemptJournal
{
    public function __construct(private OperationRunner $runner, private ExecutionStore $store, private DatabaseManager $databases) {}

    public function retain(OperationRecord $operation, AttemptRecord $attempt, MerchantRevision $merchant, ProviderLookupResult $result,
        string $source, bool $verifiedAbsence, string $receiptRef, ?string &$retainedWorkRef = null): StoredOutcome
    {
        $scope = $operation->binding->scope;
        $captured = $result->evidence;
        $foreignDelivery = $captured !== null && (get_object_vars($captured->scope) !== get_object_vars($scope)
            || $captured->providerId !== $merchant->providerId || $captured->merchantAccountRef !== $merchant->merchantAccountRef);
        $payload = [
            'schema' => 'billium.execution.transport-observation.v1',
            'operationRef' => $operation->operationRef,
            'attemptRef' => $attempt->attemptRef,
            'providerReference' => $attempt->providerReference,
            'leaseFence' => (string) $attempt->leaseFence,
            'source' => $source,
            'lookupState' => $result->state->value,
            'reasonCode' => $result->reasonCode,
            'retryAt' => $result->retryAt?->format(DATE_ATOM),
            'providerEvidenceRef' => $captured?->evidenceRef,
            'verifiedAbsence' => $verifiedAbsence,
            'absenceCertificate' => ExecutionCodec::value($result->absence),
            'foreignDelivery' => $foreignDelivery ? ExecutionCodec::value($captured) : null,
        ];
        // The runtime's original transport observation is this immutable, scoped evidence row.
        $locator = 'execution-custody:'.$receiptRef;
        $record = new ProviderEvidence($receiptRef, $scope, $merchant->providerId, $merchant->merchantAccountRef, null,
            PaymentIdentity::digest($payload), $verifiedAbsence ? 'verified_no_effect' : 'local_transport',
            'execution.transport.v1', new DateTimeImmutable, $payload, $locator);
        $spec = new OperationSpec('execution.transport_custody', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: new FinancialScope(
                $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace));

        return $this->runner->run($spec, function (TransactionContext $ctx) use ($operation, $attempt, $record, $captured, $foreignDelivery, $verifiedAbsence, &$retainedWorkRef): ExecutionReceipt {
            $retainedWorkRef = null;
            $refs = [];
            $observation = $record;
            if ($captured !== null && ! $foreignDelivery) {
                $ref = $this->store->appendEvidence($ctx, $captured);
                $work = 'provider-work:'.substr(PaymentIdentity::digest(['scope' => get_object_vars($captured->scope),
                    'handler' => $captured->handlerContractVersion, 'evidenceRef' => $ref]), 7);
                $actualWork = $this->store->enqueueProviderWork($ctx, $ref, $work, $captured->handlerContractVersion, $operation->operationRef);
                $retainedWorkRef = $actualWork;
                $fields = $record->sanitizedPayload;
                $fields['retainedProviderEvidenceRef'] = $ref;
                $fields['retainedProviderWorkRef'] = $actualWork;
                $observation = new ProviderEvidence($record->evidenceRef, $record->scope, $record->providerId, $record->merchantAccountRef, null,
                    PaymentIdentity::digest($fields), $record->signatureVerdict, $record->handlerContractVersion, $record->receivedAt, $fields, $record->securePayloadRef);
                $refs[] = $ref;
            }
            array_unshift($refs, $this->store->appendEvidence($ctx, $observation));
            if ($verifiedAbsence || $foreignDelivery) {
                $work = 'transport-work:'.substr($record->payloadDigest, 7).':'.substr($record->evidenceRef, -16);
                $this->store->enqueueProviderWork($ctx, $record->evidenceRef, $work, $record->handlerContractVersion, $operation->operationRef);
            }

            return new ExecutionReceipt($record->evidenceRef, $attempt->attemptRef, $operation->operationRef,
                $operation->binding->semanticDigest, 'evidence_retained', false, $refs);
        });
    }

    public function resumeImportedLookup(ArchivedExecutionIdentity $identity, string $transport, ProviderEvidence $evidence, string $work): void
    {
        $prior = $this->store->providerWork($work);
        if ($prior?->state !== 'quarantined' || $prior->errorCode !== 'unmatched_signed_operation') {
            return;
        }
        $scope = $identity->binding->scope;
        $spec = new OperationSpec('execution.review_archived_lookup', $scope->sellerRef, $scope->environment, FinancialMode::Recovery,
            ModuleOperationClass::UnknownResolution, ['execution', 'platform'], financialScope: new FinancialScope(
                $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $identity->binding->executionProfile));
        $this->runner->run($spec, function (TransactionContext $ctx) use ($identity, $transport, $evidence, $work, $scope): ExecutionReceipt {
            // The current owner rechecks the imported archive under the root lock before retrying this exact prior delivery.
            $receipt = $this->store->importOriginalIdentity($ctx, $identity, true);
            $this->runner->assertCurrent($ctx, 'execution');
            $db = $this->databases->connection($ctx->connectionName());
            $route = ['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment];
            $lookup = $db->table('billium_execution_provider_evidence')->where($route)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
                ->where('provider_id', $identity->providerId)->where('merchant_account_ref', $identity->binding->merchantAccountRef)
                ->where('evidence_ref', $transport)->where('handler_contract_version', 'execution.transport.v1')->where('signature_verdict', 'local_transport')
                ->whereIn('sanitized_payload->lookupState', ['found', 'pending'])->whereJsonContains('sanitized_payload', [
                    'source' => 'lookup', 'operationRef' => $identity->operationRef, 'attemptRef' => $identity->attemptRef,
                    'providerReference' => $identity->providerReference, 'providerEvidenceRef' => $evidence->evidenceRef,
                    'retainedProviderEvidenceRef' => $evidence->evidenceRef, 'retainedProviderWorkRef' => $work,
                ]);
            if (! $lookup->exists()) {
                throw new LogicException('Original lookup did not retain this exact processing identity');
            }
            $db->table('billium_execution_provider_work')->where($route)->where('work_ref', $work)->where('evidence_ref', $evidence->evidenceRef)
                ->where('operation_hint', $identity->operationRef)->where('handler_contract_version', $evidence->handlerContractVersion)
                ->where('state', 'quarantined')->where('last_error_code', 'unmatched_signed_operation')
                ->update(['state' => 'due', 'due_at' => new DateTimeImmutable]);

            return $receipt;
        })->orThrow();
    }
}
