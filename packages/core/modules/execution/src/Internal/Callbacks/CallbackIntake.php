<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Callbacks;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Internal\Custody\CallbackFrame;
use Billium\Execution\Internal\Custody\VerifyDelivery;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use DateTimeImmutable;
use Exception;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CallbackIntake implements CallbackCustody
{
    public function __construct(private OperationRunner $runner, private ExecutionStore $store, private VerifyDelivery $verification, private CallbackFrame $frames) {}

    public function receive(ExecutionScope $scope, string $merchantRevisionRef, ?string $operationHint, ProviderEvidence $delivery): StoredOutcome
    {
        $merchant = $this->store->merchantRevision($scope, $merchantRevisionRef);
        $reason = null;
        $authenticated = false;
        try {
            $verified = $this->verification->verify($scope, $merchantRevisionRef, $operationHint, $delivery, true);
            $authenticated = true;
            $operationHint = $verified->snapshot->operationRef;
            if ($verified->operation === null) {
                $reason = 'unmatched_signed_operation';
            }
        } catch (Exception) {
            $reason = 'callback_authentication_failed';
        }
        $delivery = $this->frames->retain($merchant, $delivery, $authenticated);
        if (($delivery->sanitizedPayload['archiveUnavailable'] ?? null) === true) {
            $authenticated = false;
            $reason = 'original_archive_unavailable';
        }
        $spec = new OperationSpec('execution.callback_custody', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: new FinancialScope($scope->installationRef,
                $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace));
        $result = $this->runner->run($spec, function (TransactionContext $ctx) use ($delivery, $scope, $operationHint, $reason): ExecutionReceipt {
            $ref = $this->store->appendEvidence($ctx, $delivery);
            $work = 'provider-work:'.substr(PaymentIdentity::digest(['scope' => get_object_vars($scope),
                'handler' => $delivery->handlerContractVersion, 'evidenceRef' => $ref]), 7);
            $work = $this->store->enqueueProviderWork($ctx, $ref, $work, $delivery->handlerContractVersion, $operationHint);
            if ($reason !== null && $ref === $delivery->evidenceRef) {
                $claim = $this->store->claimProviderWork($ctx, $work, 'callback-custody', new DateTimeImmutable('+1 minute'));
                if ($claim !== null && ! $this->store->finishProviderWork($ctx, $claim, 'quarantined', $ref, $reason, null)) {
                    throw new LogicException('Callback quarantine fence changed');
                }
            }

            return new ExecutionReceipt($ref, $work, $operationHint ?? $ref, $delivery->payloadDigest,
                $reason === null && $ref === $delivery->evidenceRef ? 'custody_accepted' : 'custody_quarantined', false, [$ref]);
        });
        if (! $authenticated && $result->accepted) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Callback failed authentication and was retained');
        }

        return $result;
    }
}
