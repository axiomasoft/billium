<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Disputes;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Internal\Reconciliation\ReconciliationJournal;
use Billium\Execution\Internal\Reconciliation\RecoveryWindow;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class InventoryComparison
{
    public function __construct(private ReconciliationJournal $journal, private ExecutionStore $store) {}

    /** @return array<string,mixed> */
    public function basis(TransactionContext $ctx, RecoveryWindow $w, ?string $root): array
    {
        if ($root === null) {
            return ['status' => 'no_local_binding'];
        }
        $this->journal->lockRoot($ctx, $w->scope, $root);
        $op = $this->store->operation($w->scope, $root, $ctx);
        if ($op === null) {
            return ['status' => 'no_local_binding'];
        }
        $this->assertOriginal($w, $op);
        if ($op->rootOperationRef !== $root) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Comparison must name the original root');
        }

        return ComparisonBasis::locked($this->journal->db($ctx), $op, $this->store->capacity($ctx, $root));
    }

    public function assertOriginal(RecoveryWindow $w, OperationRecord $operation): void
    {
        $binding = $operation->binding;
        if (get_object_vars($binding->scope) !== get_object_vars($w->scope) || $binding->executionProfile !== $w->source || $binding->merchantRevisionRef !== $w->merchant->revisionRef
            || $binding->merchantAccountRef !== $w->merchant->merchantAccountRef || $operation->requested->currency->code !== $w->currency) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Inventory line differs from the original operation route');
        }
    }

    /**
     * @param  array<string,mixed>  $basis
     * @param  array<string,mixed>  $observed
     */
    public function review(TransactionContext $ctx, RecoveryWindow $w, string $factRef, string $code, ?string $root, string $evidenceRef,
        string $sourceDigest, array $basis, array $observed, bool $consistent = false): ?string
    {
        $db = $this->journal->db($ctx);
        $case = 'review:'.substr(ExecutionCodec::digest([$w->scope, $w->source, $w->merchant->revisionRef, $w->currency, $factRef, $code]), 7);
        $last = $db->table('billium_execution_recon_review')->where($w->scopeFields())->where('owner_namespace', $w->scope->intentOwnerNamespace)
            ->where('source_authority', $w->source)->where('case_ref', $case)->orderByDesc('id')->first();
        if ($consistent && ($last === null || $last->status === 'resolved')) {
            return null;
        }
        if ($last !== null) {
            $prev = ExecutionCodec::map(ReconciliationJournal::string($last->observed));
            if (is_string($observed['observedAt'] ?? null) && is_string($prev['observedAt'] ?? null)
                && new DateTimeImmutable($observed['observedAt']) < new DateTimeImmutable($prev['observedAt'])) {
                return $last->status === 'open' ? ReconciliationJournal::string($last->event_ref) : null;
            }
        }
        $basisDigest = ExecutionCodec::digest($basis);
        $event = 'comparison:'.substr(ExecutionCodec::digest([$case, $basisDigest, $observed, $consistent]), 7);
        $db->table('billium_execution_recon_review')->insertOrIgnore($w->scopeFields() + [
            'owner_namespace' => $w->scope->intentOwnerNamespace, 'source_authority' => $w->source, 'merchant_account_ref' => $w->merchant->merchantAccountRef,
            'revision_ref' => $w->merchant->revisionRef, 'currency' => $w->currency, 'root_ref' => $root, 'case_ref' => $case, 'event_ref' => $event,
            'status' => $consistent ? 'resolved' : 'open', 'code' => $code, 'evidence_ref' => $evidenceRef, 'source_digest' => $sourceDigest,
            'expected' => ExecutionCodec::encode($basis), 'observed' => ExecutionCodec::encode($observed), 'basis_digest' => $basisDigest,
            'provenance' => $consistent ? 'subsequent_consistent_comparison' : ($w->attempt === null ? 'original_provider_inventory' : 'original_attempt_recovery'), 'receipt_ref' => $consistent ? $w->ref : null,
            'severity' => $code === 'fee' || $code === 'settlement' ? 'review' : 'high', 'owner_ref' => $w->scope->intentOwnerNamespace,
            'created_at' => ReconciliationJournal::stamp(new DateTimeImmutable), 'next_review_at' => $consistent ? null : ReconciliationJournal::stamp(new DateTimeImmutable),
        ]);

        return $consistent ? null : $event;
    }
}
