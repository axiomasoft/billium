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
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class AttemptJournal
{
    public function __construct(private OperationRunner $runner, private ExecutionStore $store) {}

    public function retain(OperationRecord $operation, AttemptRecord $attempt, MerchantRevision $merchant, ProviderLookupResult $result,
        string $source, bool $verifiedAbsence, string $receiptRef): StoredOutcome
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

        return $this->runner->run($spec, function (TransactionContext $ctx) use ($operation, $attempt, $record, $captured, $foreignDelivery, $verifiedAbsence): ExecutionReceipt {
            $refs = [];
            $observation = $record;
            if ($captured !== null && ! $foreignDelivery) {
                $ref = $this->store->appendEvidence($ctx, $captured);
                $work = 'provider-work:'.substr(PaymentIdentity::digest(['scope' => get_object_vars($captured->scope),
                    'handler' => $captured->handlerContractVersion, 'evidenceRef' => $ref]), 7);
                $this->store->enqueueProviderWork($ctx, $ref, $work, $captured->handlerContractVersion);
                $fields = $record->sanitizedPayload;
                $fields['retainedProviderEvidenceRef'] = $ref;
                $observation = new ProviderEvidence($record->evidenceRef, $record->scope, $record->providerId, $record->merchantAccountRef, null,
                    PaymentIdentity::digest($fields), $record->signatureVerdict, $record->handlerContractVersion, $record->receivedAt, $fields, $record->securePayloadRef);
                $refs[] = $ref;
            }
            array_unshift($refs, $this->store->appendEvidence($ctx, $observation));
            if ($verifiedAbsence || $foreignDelivery) {
                $work = 'transport-work:'.substr($record->payloadDigest, 7).':'.substr($record->evidenceRef, -16);
                $this->store->enqueueProviderWork($ctx, $record->evidenceRef, $work, $record->handlerContractVersion);
            }

            return new ExecutionReceipt($record->evidenceRef, $attempt->attemptRef, $operation->operationRef,
                $operation->binding->semanticDigest, 'evidence_retained', false, $refs);
        });
    }
}
