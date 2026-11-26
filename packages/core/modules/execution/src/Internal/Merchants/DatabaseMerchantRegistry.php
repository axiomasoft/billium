<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Merchants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Execution\Internal\Storage\ExecutionRows;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseMerchantRegistry implements MerchantActionGate, MerchantRegistry
{
    public function __construct(private DatabaseExecutionStore $store, private DatabaseManager $databases, private OperationRunner $runner, private CallerContext $callers, private BilliumOptions $options) {}

    public function forAdmission(ExecutionScope $executionScope, string $merchantRevisionRef): MerchantRevision
    {
        $revision = $this->store->merchantRevision($executionScope, $merchantRevisionRef);
        if (! $this->actionAllowed($revision, 'new', new DateTimeImmutable)) {
            $this->refuse('Merchant revision is not effective and explicitly enabled for new operations');
        }

        return $revision;
    }

    public function historical(ExecutionScope $executionScope, string $rootOperationRef): MerchantRevision
    {
        $root = $this->store->operation($executionScope, $rootOperationRef) ?? throw new LogicException('Historical operation unavailable');
        if ($root->rootOperationRef !== $rootOperationRef) {
            $this->refuse('Historical selector must be original root');
        }

        return $this->store->merchantRevision($executionScope, $root->binding->merchantRevisionRef);
    }

    public function capabilities(string $snapshotRef): CapabilitySnapshot
    {
        $caller = $this->callers->current();
        if ($caller->intentOwnerNamespaces === []) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Merchant capabilities require an authenticated business owner');
        }
        $row = $this->connection()->table('billium_execution_capability_snapshot')
            ->where('installation_ref', $caller->installationRef)->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)->where('snapshot_ref', $snapshotRef)->first() ?? throw new LogicException('Capability snapshot absent');

        return ExecutionCodec::decode(CapabilitySnapshot::class, ExecutionCodec::text($row->body));
    }

    public function allowed(ExecutionScope $scope, string $revisionRef, string $purpose, DateTimeImmutable $at): bool
    {
        return $this->actionAllowed($this->store->merchantRevision($scope, $revisionRef), $purpose, $at);
    }

    public function lock(TransactionContext $context, ExecutionScope $scope, string $revisionRef, string $purpose, ?ResolvedCredentials $credentials = null): void
    {
        $this->runner->assertCurrent($context, 'execution');
        $selected = $context->spec()->financialScope;
        if (! $context->spec()->mode->financial() || $selected === null || $selected->intentOwnerNamespace !== $scope->intentOwnerNamespace
            || ! in_array($selected->sourceAuthority, ['remote-billium', 'standalone-billium'], true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Merchant action requires a selected financial scope');
        }
        $revision = $this->lockRevision($context, $scope, $revisionRef, shared: true);
        $at = new DateTimeImmutable;
        if (! $this->actionAllowed($revision, $purpose, $at, $context)) {
            $this->refuse('Merchant action is disabled or outside its effective interval');
        }
        if ($credentials !== null) {
            $this->assertCredential($revision, $purpose, $credentials, $at, $context);
        }
    }

    public function assertCredential(MerchantRevision $revision, string $purpose, ResolvedCredentials $credentials, DateTimeImmutable $at, ?TransactionContext $context = null): void
    {
        if ($credentials->merchantAccountRef !== $revision->merchantAccountRef || $credentials->purpose !== $purpose || $credentials->expiresAt <= $at) {
            $this->refuse('Credential identity, purpose or expiry differs from the original merchant action');
        }
        $instant = ExecutionRows::stamp($at);
        $query = $this->query('merchant_credential_revision', $revision->scope, $context)
            ->where('revision_ref', $revision->revisionRef)->where('merchant_account_ref', $revision->merchantAccountRef)->where('purpose', $purpose);
        $credential = (clone $query)->where('credential_version_ref', $credentials->credentialVersionRef)
            ->where('credential_ref', $credentials->secretRef->locator)->where('identity_continuity_evidence_ref', $credentials->continuityProofRef)
            ->where('valid_from', '<=', $instant)->where('valid_until', '>=', ExecutionRows::stamp($credentials->expiresAt))->value('credential_revision_ref');
        // Once a successor takes effect its predecessor stays retired, including after the successor expires.
        if (! is_string($credential) || $query->where('predecessor_ref', $credential)->where('valid_from', '<=', $instant)->exists()) {
            $this->refuse('Credential continuity is unavailable for this original account, action and horizon');
        }
    }

    private function actionAllowed(MerchantRevision $revision, string $purpose, DateTimeImmutable $at, ?TransactionContext $context = null): bool
    {
        $policy = $revision->policy;
        if ($purpose === 'new' && ($at < $policy->validFrom || ($policy->validUntil !== null && $at >= $policy->validUntil))) {
            return false;
        }
        $event = $this->query('merchant_lifecycle_event', $revision->scope, $context)->where('revision_ref', $revision->revisionRef)
            ->where('purpose', $purpose)->where('effective_at', '<=', ExecutionRows::stamp($at))->orderByDesc('effective_at')->orderByDesc('id')->first();
        if ($event === null) {
            return false;
        }

        return $purpose === 'new' ? $event->action === 'enable_new' : $event->action === 'enable_recovery' && $event->retention_until !== null
            && new DateTimeImmutable(ExecutionCodec::text($event->retention_until)) > $at;
    }

    private function lockRevision(TransactionContext $context, ExecutionScope $scope, string $revisionRef, bool $shared): MerchantRevision
    {
        $context->assertParticipant('execution', $this->options->connection ?? $this->databases->getDefaultConnection());
        $caller = $context->caller();
        $selected = $context->spec()->financialScope;
        if ([$scope->installationRef, $scope->sellerRef, $scope->environment] !== [$caller->installationRef, $caller->sellerRef, $caller->environment]
            || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ($selected !== null && $selected->intentOwnerNamespace !== $scope->intentOwnerNamespace)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Merchant revision outside current root scope');
        }
        $query = $this->query('merchant_revision', $scope, $context)->where('revision_ref', $revisionRef);
        $original = $query->value('id');
        if (! is_int($original) && (! is_string($original) || ! ctype_digit($original))) {
            $this->refuse('Original merchant revision unavailable');
        }
        $id = (int) $original;
        $this->connection($context)->selectOne("SELECT set_config('billium.merchant_gate_installation', ?, true)", [$caller->installationRef]);
        $keys = $context->lock(new LockRequest(LockRank::MerchantRevision, 'billium_execution_merchant_revision', [$id],
            shared: $shared, guardedRoutine: 'billium_lock_execution_merchant_revision'));
        if (count($keys) !== 1 || (string) $keys[0] !== (string) $id || $query->value('id') !== $original) {
            throw new LockPlanChanged('Original merchant revision changed under lock');
        }

        return $this->store->merchantRevision($scope, $revisionRef, $context);
    }

    public function appendLifecycle(TransactionContext $context, ExecutionScope $scope, string $revisionRef, string $eventRef, string $action, string $purpose, DateTimeImmutable $effectiveAt, ?DateTimeImmutable $retentionUntil, string $reason, string $evidenceRef): void
    {
        $this->admin($context, $scope);
        $this->lockRevision($context, $scope, $revisionRef, shared: false);
        if ($evidenceRef === '' || ($action === 'enable_recovery' && ($retentionUntil === null || $retentionUntil <= $effectiveAt))) {
            $this->refuse('Lifecycle permission needs explicit evidence and recovery horizon');
        }
        $this->connection($context)->table('billium_execution_merchant_lifecycle_event')->insert(['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment, 'event_ref' => $eventRef, 'revision_ref' => $revisionRef, 'action' => $action, 'purpose' => $purpose, 'effective_at' => ExecutionRows::stamp($effectiveAt), 'retention_until' => ExecutionRows::nullableStamp($retentionUntil), 'actor_ref' => $context->caller()->actorRef, 'reason' => $reason, 'evidence_ref' => $evidenceRef]);
    }

    public function appendCredential(TransactionContext $context, MerchantRevision $revision, string $credentialRevisionRef, string $credentialVersionRef, SecretRef $locator, string $purpose, DateTimeImmutable $validFrom, DateTimeImmutable $validUntil, string $continuityEvidenceRef, ?string $predecessorRef): void
    {
        $this->admin($context, $revision->scope);
        $original = $this->lockRevision($context, $revision->scope, $revision->revisionRef, shared: false);
        if (ExecutionCodec::differs($original, $revision) || $continuityEvidenceRef === '' || $validUntil <= $validFrom) {
            $this->refuse('Credential rotation lacks immutable identity continuity');
        }
        $this->connection($context)->table('billium_execution_merchant_credential_revision')->insert(['installation_ref' => $revision->scope->installationRef, 'seller_ref' => $revision->scope->sellerRef, 'environment' => $revision->scope->environment, 'credential_revision_ref' => $credentialRevisionRef, 'revision_ref' => $revision->revisionRef, 'merchant_account_ref' => $revision->merchantAccountRef, 'credential_ref' => $locator->locator, 'credential_version_ref' => $credentialVersionRef, 'purpose' => $purpose, 'valid_from' => ExecutionRows::stamp($validFrom), 'valid_until' => ExecutionRows::stamp($validUntil), 'identity_continuity_evidence_ref' => $continuityEvidenceRef, 'predecessor_ref' => $predecessorRef]);
    }

    private function admin(TransactionContext $context, ExecutionScope $scope): void
    {
        $this->runner->assertCurrent($context, 'execution');
        if ($context->spec()->mode !== FinancialMode::Administrative || [$scope->installationRef, $scope->sellerRef, $scope->environment] !== [$context->caller()->installationRef, $context->caller()->sellerRef, $context->caller()->environment]) {
            throw new LogicException('Merchant administration requires current scoped admin root');
        }
    }

    private function connection(?TransactionContext $context = null): Connection
    {
        return $this->databases->connection($context?->connectionName() ?? $this->options->connection);
    }

    private function query(string $table, ExecutionScope $scope, ?TransactionContext $context = null): Builder
    {
        return $this->connection($context)->table('billium_execution_'.$table)->where('installation_ref', $scope->installationRef)->where('seller_ref', $scope->sellerRef)->where('environment', $scope->environment);
    }

    private function refuse(string $reason): never
    {
        throw new ProblemException(ProblemCode::ValidationInvalid, $reason);
    }
}
