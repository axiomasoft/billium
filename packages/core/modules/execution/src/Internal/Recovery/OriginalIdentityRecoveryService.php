<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Recovery;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Contracts\OriginalIdentityRecovery;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\LookupRequest;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Reducer\MaterialRules;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Contracts\Installation\Installation;
use Closure;
use DateTimeImmutable;
use Exception;
use Packman\Attributes\Internal;

#[Internal]
final readonly class OriginalIdentityRecoveryService implements OriginalIdentityRecovery
{
    /** @param Closure():?OriginalIdentityArchive $archive */
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private FinancialSessionAdmission $sessions,
        private Installation $installation, private ExecutionStore $store, private TransportBoundary $boundary, private MerchantRegistry $merchants,
        private QualifiedAdapter $adapters, private ContinuityMerchantCredentials $credentials, private AttemptJournal $journal, private EvidenceReducer $reducer,
        private Closure $archive) {}

    public function recover(ExecutionScope $scope, string $sourceAuthority, string $account, string $reference): ReductionResult
    {
        $this->boundary->assertOutsideTransaction();
        $caller = $this->callers->current();
        $selected = PaymentIdentity::select($caller, $scope, $sourceAuthority);
        if (! in_array('execution.recover_held', $caller->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Original identity recovery requires a recovery operator');
        }
        $token = $this->sessions->currentToken($scope->installationRef);
        $session = $this->installation->currentSession($scope->installationRef);
        if ($account === '' || $reference === '' || $session?->state !== 'recovery' || $token === null || $token->expired(new DateTimeImmutable)
            || ! $token->permits(FinancialMode::Recovery)) {
            throw self::refused('A current recovery session and account inventory reference are required');
        }
        $modules = ['execution', 'platform'];
        $verify = new OperationSpec('execution.review_original_identity', $scope->sellerRef, $scope->environment, FinancialMode::Recovery,
            ModuleOperationClass::UnknownResolution, $modules, commitKind: 'execution.original_identity_review', financialScope: $selected);
        // Persist the hold separately. Failed archive verification, lookup, or reduction must leave the namespace held.
        $this->runner->run($verify, function (TransactionContext $ctx) use ($scope, $reference): ExecutionReceipt {
            $ns = $this->store->namespace($scope, $ctx);
            if ($ns->state === NamespaceState::Open || $ns->generation === 0) {
                $this->store->transitionNamespace($ctx, $scope, NamespaceState::RecoveryRequired, $ns->generation, 'archive-review:'.hash('sha256', $reference));
            }

            return new ExecutionReceipt($reference, $reference, $reference, 'archive-review', 'held', false, []);
        })->orThrow();
        try {
            $identity = ($this->archive)()?->authenticate($scope, $account, $reference, $token, $session->epoch);
        } catch (Exception) {
            throw self::refused('Independent original dispatch or commercial source authentication failed');
        }
        if ($identity === null || get_object_vars($identity->binding->scope) !== get_object_vars($scope) || $identity->binding->executionProfile !== $sourceAuthority
            || $identity->providerReference !== $reference || $identity->binding->merchantAccountRef !== $account) {
            throw self::refused('Independent dispatch manifest and commercial source do not establish this inventory identity');
        }
        $spec = new OperationSpec($identity->commandSchema(), $scope->sellerRef, $scope->environment, FinancialMode::Recovery, ModuleOperationClass::UnknownResolution,
            $modules, $identity->qualifiedKeyDigest, $identity->semanticDigest, 'execution.import_original_identity', $selected, $identity->inputDigest,
            originalExecutionBindingRef: $identity->binding->rootOperationRef);
        $outcome = $this->runner->run($spec, fn (TransactionContext $ctx): ExecutionReceipt => $this->store->importOriginalIdentity($ctx, $identity))->orThrow();
        if ($outcome->replayed) {
            // A keyed replay can complete before the financial root. Recheck the exact import under the current recovery guard.
            $this->runner->run($verify, fn (TransactionContext $ctx): ExecutionReceipt => $this->store->importOriginalIdentity($ctx, $identity, true))->orThrow();
        }

        return $this->lookup($identity);
    }

    private function lookup(ArchivedExecutionIdentity $identity): ReductionResult
    {
        $binding = $identity->binding;
        $scope = $binding->scope;
        $operation = $this->store->operation($scope, $identity->operationRef) ?? throw self::refused('Recovered original operation unavailable');
        $attempt = $this->store->attempt($scope, $identity->attemptRef) ?? throw self::refused('Recovered original attempt unavailable');
        $merchant = $this->merchants->historical($scope, $binding->rootOperationRef);
        [$driver, $capability] = $this->adapters->select($merchant, $identity->kind, $identity->requested->currency->code);
        $retainedId = $this->store->providerOperationId($scope, $identity->attemptRef);
        if ($retainedId !== null && $identity->providerOperationId !== null && $retainedId !== $identity->providerOperationId) {
            throw self::refused('The archived assigned provider ID conflicts with the original attempt');
        }
        $expectedId = $retainedId ?? $identity->providerOperationId;
        $assigned = $capability->details->lookupByProviderId ? $expectedId : null;
        if (($assigned === null && ! $capability->lookupByReference) || ($identity->kind === EffectKind::Refund && ! $capability->details->refundLookup)) {
            throw self::refused('Original-account provider lookup unavailable');
        }
        $credentials = $this->credentials->resolve($merchant, 'lookup');
        $result = $driver->lookup(new LookupRequest($binding, $identity->operationRef, $identity->providerReference, $assigned, $credentials));
        $ref = 'transport:'.bin2hex(random_bytes(20));
        $evidence = $result->evidence;
        if ($evidence !== null) {
            try {
                $material = $driver->normalize($evidence, $capability);
            } catch (Exception) {
                $material = null;
            }
            $reported = $result->snapshot;
            if ($material === null || ($expectedId !== null && $material->providerOperationId !== $expectedId)
                || $material->providerReference !== $identity->providerReference || $material->operationRef !== $identity->operationRef
                || $material->kind !== $identity->kind || $material->bindingDigest !== AuthorityClaims::bindingDigest($binding)
                || $material->merchantAccountRef !== $binding->merchantAccountRef || $material->evidenceRefs !== [$evidence->evidenceRef]
                || $reported === null || $reported->materialDigest !== $material->materialDigest || MaterialRules::digest($reported) !== MaterialRules::digest($material)
                || $reported->providerRevision !== $material->providerRevision || $reported->stableEffectId !== $material->stableEffectId
                || $reported->providerOperationId !== $material->providerOperationId || $reported->providerReference !== $material->providerReference
                || $reported->bindingDigest !== $material->bindingDigest) {
                // Retain the full foreign delivery as unverified local transport evidence, ineligible for monetary reduction.
                $body = ['reason' => 'archived_provider_id_mismatch', 'received' => ExecutionCodec::value($evidence)];
                $raw = new ProviderEvidence('review:'.$ref, $scope, $merchant->providerId, $merchant->merchantAccountRef, null,
                    PaymentIdentity::digest($body), 'unverified', 'execution.transport.v1', new DateTimeImmutable, $body, 'execution-custody:'.$ref);
                $mismatch = new ProviderLookupResult(ProviderLookupState::Ambiguous, $raw, null, null, 'archived_provider_id_mismatch', null);
                $this->journal->retain($operation, $attempt, $merchant, $mismatch, 'lookup', false, $ref)->orThrow();
                throw self::refused('Provider lookup material differs from its authenticated archived assignment');
            }
        }
        $work = null;
        $receipt = $this->journal->retain($operation, $attempt, $merchant, $result, 'lookup', false, $ref, $work)->orThrow();
        $refs = $receipt->body['evidenceRefs'] ?? null;
        if ($evidence === null || $work === null || ! is_array($refs) || ($refs[1] ?? null) !== $evidence->evidenceRef) {
            throw self::refused('Original provider lookup has no scoped authenticated evidence to reduce');
        }
        $this->journal->resumeImportedLookup($identity, $ref, $evidence, $work);

        return $this->reducer->reduce($work);
    }

    private static function refused(string $reason): ProblemException
    {
        return new ProblemException(ProblemCode::RecoveryRequired, $reason);
    }
}
