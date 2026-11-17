<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Merchants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseMerchantRegistry implements MerchantRegistry
{
    public function __construct(private DatabaseExecutionStore $store, private DatabaseManager $databases, private OperationRunner $runner, private CallerContext $callers, private BilliumOptions $options) {}

    public function forAdmission(ExecutionScope $executionScope, string $merchantRevisionRef): MerchantRevision
    {
        $revision = $this->store->merchantRevision($executionScope, $merchantRevisionRef);
        $now = new DateTimeImmutable;
        $row = $this->query('merchant_revision', $executionScope)->where('revision_ref', $merchantRevisionRef)->where('valid_from', '<=', $now)->where(static fn (Builder $q): Builder => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now))->first();
        if ($row === null || ! $this->allowed($executionScope, $merchantRevisionRef, 'new', $now)) {
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
        $this->store->merchantRevision($scope, $revisionRef);
        $event = $this->query('merchant_lifecycle_event', $scope)->where('revision_ref', $revisionRef)->where('purpose', $purpose)->where('effective_at', '<=', $at)->orderByDesc('effective_at')->orderByDesc('id')->first();
        if ($event === null || ! in_array($event->action, ['enable_new', 'enable_recovery'], true)) {
            return false;
        }

        return $purpose === 'new' || ($event->retention_until !== null && new DateTimeImmutable(is_string($event->retention_until) ? $event->retention_until : throw new LogicException('Invalid retention timestamp')) > $at);
    }

    public function appendLifecycle(TransactionContext $context, ExecutionScope $scope, string $revisionRef, string $eventRef, string $action, string $purpose, DateTimeImmutable $effectiveAt, ?DateTimeImmutable $retentionUntil, string $reason, string $evidenceRef): void
    {
        $this->admin($context, $scope);
        $this->store->merchantRevision($scope, $revisionRef);
        if ($evidenceRef === '' || ($action === 'enable_recovery' && ($retentionUntil === null || $retentionUntil <= $effectiveAt))) {
            $this->refuse('Lifecycle permission needs explicit evidence and recovery horizon');
        }
        $this->connection($context)->table('billium_execution_merchant_lifecycle_event')->insert(['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment, 'event_ref' => $eventRef, 'revision_ref' => $revisionRef, 'action' => $action, 'purpose' => $purpose, 'effective_at' => $effectiveAt, 'retention_until' => $retentionUntil, 'actor_ref' => $context->caller()->actorRef, 'reason' => $reason, 'evidence_ref' => $evidenceRef]);
    }

    public function appendCredential(TransactionContext $context, MerchantRevision $revision, string $credentialRevisionRef, string $credentialVersionRef, SecretRef $locator, string $purpose, DateTimeImmutable $validFrom, DateTimeImmutable $validUntil, string $continuityEvidenceRef, ?string $predecessorRef): void
    {
        $this->admin($context, $revision->scope);
        $original = $this->store->merchantRevision($revision->scope, $revision->revisionRef);
        if (ExecutionCodec::encode($original) !== ExecutionCodec::encode($revision) || $continuityEvidenceRef === '' || $validUntil <= $validFrom) {
            $this->refuse('Credential rotation lacks immutable identity continuity');
        }
        $this->connection($context)->table('billium_execution_merchant_credential_revision')->insert(['installation_ref' => $revision->scope->installationRef, 'seller_ref' => $revision->scope->sellerRef, 'environment' => $revision->scope->environment, 'credential_revision_ref' => $credentialRevisionRef, 'revision_ref' => $revision->revisionRef, 'merchant_account_ref' => $revision->merchantAccountRef, 'credential_ref' => $locator->locator, 'credential_version_ref' => $credentialVersionRef, 'purpose' => $purpose, 'valid_from' => $validFrom, 'valid_until' => $validUntil, 'identity_continuity_evidence_ref' => $continuityEvidenceRef, 'predecessor_ref' => $predecessorRef]);
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

    private function query(string $table, ExecutionScope $scope): Builder
    {
        return $this->connection()->table('billium_execution_'.$table)->where('installation_ref', $scope->installationRef)->where('seller_ref', $scope->sellerRef)->where('environment', $scope->environment);
    }

    private function refuse(string $reason): never
    {
        throw new ProblemException(ProblemCode::ValidationInvalid, $reason);
    }
}
