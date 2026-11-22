<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Migration;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\NamespaceDisposition;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Platform\Contracts\Migration\AuthorityHandoff;
use Billium\Platform\Contracts\Migration\SourceHandoffHost;
use Billium\Platform\Contracts\Migration\TargetHandoffHost;
use Billium\Platform\Data\Feed\FeedPosition;
use Billium\Platform\Data\Migration\HandoffActivation;
use Billium\Platform\Data\Migration\HandoffPlan;
use Billium\Platform\Data\Migration\HandoffRecoveryRequired;
use Billium\Platform\Data\Migration\LedgerScope;
use Billium\Platform\Data\Migration\MigrationManifest;
use Billium\Platform\Data\Migration\ReconciliationChecksum;
use Billium\Platform\Data\Migration\SealedTail;
use Billium\Platform\Data\Migration\SourceFreezeCertificate;
use Billium\Platform\Data\Migration\TargetReconciliation;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class HostAuthorityHandoff implements AuthorityHandoff
{
    public function __construct(private SourceHandoffHost $source, private TargetHandoffHost $target) {}

    public function stage(HandoffPlan $plan): void
    {
        $this->target->stageRecovery($plan);
        $this->requireRecoveryTarget($plan);
    }

    public function freeze(HandoffPlan $plan): SourceFreezeCertificate
    {
        $this->requireRecoveryTarget($plan);
        $freeze = $this->source->freeze($plan);
        if ($freeze->plan->digest() !== $plan->digest() || ! $this->source->isSealed($freeze)) {
            throw new HandoffRecoveryRequired('Source seal or freeze scope is unverified');
        }

        return $freeze;
    }

    public function snapshot(SourceFreezeCertificate $freeze): MigrationManifest
    {
        $this->requireRecoveryTarget($freeze->plan);
        if (! $this->source->isSealed($freeze)) {
            throw new HandoffRecoveryRequired('Source seal is no longer valid');
        }
        $manifest = $this->source->snapshot($freeze);
        if ($manifest->freeze->digest() !== $freeze->digest() || $manifest->retentionLeaseExpiresAt <= new DateTimeImmutable) {
            throw new HandoffRecoveryRequired('Snapshot freeze or retention lease mismatch');
        }

        return $manifest;
    }

    public function verify(MigrationManifest $manifest): TargetReconciliation
    {
        $this->requireRecoveryTarget($manifest->plan);
        $tail = $this->verifiedTail($manifest);
        $this->target->applyAndVerify($manifest, $tail);

        return $this->verifiedReceipt($manifest, $this->verifiedTail($manifest));
    }

    public function activate(MigrationManifest $manifest, HandoffActivation $authorization): void
    {
        $receipt = $this->verifiedReceipt($manifest, $this->verifiedTail($manifest));
        if ($authorization->manifestDigest !== $manifest->digest() || $authorization->targetReceiptDigest !== $receipt->digest()) {
            throw new HandoffRecoveryRequired('Activation authorization differs from the manifest or reconciliation receipt');
        }
        $this->requireCertificate($manifest->plan->source, $manifest->plan->target, $authorization->retirement, $authorization->certificate);
        if (! $this->target->activate($manifest, $receipt, $authorization)) {
            throw new HandoffRecoveryRequired('Target host refused exclusive activation');
        }
    }

    public function rollback(SourceFreezeCertificate $freeze, RetirementProof $retirement, ActivationCertificate $certificate): void
    {
        if (! $this->source->isSealed($freeze) || $certificate->newOwner->authorityEpoch <= $freeze->plan->source->owner->authorityEpoch
            || $certificate->newOwner->installationRef !== $freeze->plan->source->owner->installationRef) {
            throw new HandoffRecoveryRequired('Rollback requires a sealed source and a new authority epoch');
        }
        $source = $freeze->plan->source;
        $next = new LedgerScope($source->feed, $certificate->newOwner, $source->books, $source->entityRefs);
        $this->requireCertificate($source, $next, $retirement, $certificate);
        $proof = $this->target->fenceForRollback($freeze->plan);
        if ($proof === null || $proof->planDigest !== $freeze->plan->digest()
            || ! $this->source->resumeSource($freeze, $proof, $retirement, $certificate)) {
            throw new HandoffRecoveryRequired('Rollback requires a fenced target with proof of zero monetary commits and effects');
        }
    }

    private function requireRecoveryTarget(HandoffPlan $plan): void
    {
        if (! $this->target->isRecovering($plan)) {
            throw new HandoffRecoveryRequired('The exact target attachment must be staged in recovery');
        }
    }

    private function verifiedTail(MigrationManifest $manifest): SealedTail
    {
        if ($manifest->retentionLeaseExpiresAt <= new DateTimeImmutable || ! $this->source->isSealed($manifest->freeze)) {
            throw new HandoffRecoveryRequired('Source seal or retention lease is no longer valid');
        }
        $tail = $this->source->sealedTail($manifest);
        if ($tail === null || $tail->manifestDigest !== $manifest->digest()
            || self::comparePosition($tail->terminalCursor->position, $manifest->safeFeedCursor->position) < 0) {
            throw new HandoffRecoveryRequired('Snapshot or contiguous selected D52 tail is unverified');
        }

        return $tail;
    }

    private function verifiedReceipt(MigrationManifest $manifest, SealedTail $tail): TargetReconciliation
    {
        $receipt = $this->target->reconciliation($manifest);
        if ($receipt === null || $receipt->manifestDigest !== $manifest->digest() || $receipt->tailDigest !== $tail->digest()
            || $receipt->adoptedCursor->token !== $tail->terminalCursor->token
            || $receipt->adoptedCursor->position->toArray() !== $tail->terminalCursor->position->toArray()
            || self::checksums($receipt->checksums) !== self::checksums($manifest->checksums)) {
            throw new HandoffRecoveryRequired('Target dispositions, adopted cursor or reconciliation differ from the source');
        }

        return $receipt;
    }

    private function requireCertificate(LedgerScope $old, LedgerScope $next, RetirementProof $retirement, ActivationCertificate $certificate): void
    {
        $now = new DateTimeImmutable;
        $coverage = $certificate->coverage;
        if (get_object_vars($retirement->oldOwner) !== get_object_vars($old->owner)
            || get_object_vars($certificate->newOwner) !== get_object_vars($next->owner)
            || $certificate->verdict !== 'activate' || $certificate->localEpoch < 1
            || $certificate->retirementProofDigest !== $retirement->signatureDigest || $certificate->expiresAt <= $now
            || $retirement->issuedAt > $now || $coverage->recoveryHorizon > $now || $coverage->backupLineageRef !== $old->feed->lineage
            || $coverage->projectionValidationReceipts === [] || $coverage->downstreamValidationReceipts === []
            || ! array_any($coverage->namespaceStates, static fn (NamespaceDisposition $namespace): bool => $namespace->state === 'active' && $namespace->scope->installationRef === $next->feed->installationRef
                && $namespace->scope->sellerRef === $next->feed->sellerRef && $namespace->scope->environment === $next->feed->environment
                && ($namespace->scope->sourceAuthority === null || $namespace->scope->sourceAuthority === $next->feed->sourceAuthority))) {
            throw new HandoffRecoveryRequired('Retirement, activation scope or selected lineage certificate is invalid');
        }
    }

    private static function comparePosition(FeedPosition $left, FeedPosition $right): int
    {
        $tx = self::compareDecimal($left->txId, $right->txId);

        return $tx === 0 ? self::compareDecimal($left->id, $right->id) : $tx;
    }

    private static function compareDecimal(string $left, string $right): int
    {
        $length = strlen($left) <=> strlen($right);

        return $length === 0 ? strcmp($left, $right) : $length;
    }

    /** @param list<ReconciliationChecksum> $summaries */
    private static function checksums(array $summaries): string
    {
        return (new Codec)->digest(array_map(static fn (ReconciliationChecksum $summary): array => $summary->toArray(), $summaries));
    }
}
