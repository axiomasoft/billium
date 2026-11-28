<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Migration;

use Billium\Platform\Data\Migration\HandoffActivation;
use Billium\Platform\Data\Migration\HandoffPlan;
use Billium\Platform\Data\Migration\MigrationManifest;
use Billium\Platform\Data\Migration\SealedTail;
use Billium\Platform\Data\Migration\TargetNoEffectsProof;
use Billium\Platform\Data\Migration\TargetReconciliation;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Target authority and attachment generation come from the host outside restored ledger state. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TargetHandoffHost
{
    /** Privileged stage durably binds one immutable plan to the recovery-only attachment. Idempotent by exact plan. */
    public function stageRecovery(HandoffPlan $plan): void;

    public function isRecovering(HandoffPlan $plan): bool;

    /**
     * Verify transferred snapshot and tail bytes before applying. Use current recovery session, source mappings,
     * stable economic revisions and existing APIs (no PSP, rating, allocation replay or cash export). Atomically
     * adopt the verified cursor with the receipt only after all durable dispositions and checksum equality.
     * Retry with the same manifest and tail is idempotent. A conflicting retry or evidence gap returns no receipt.
     */
    public function applyAndVerify(MigrationManifest $manifest, SealedTail $tail): void;

    public function reconciliation(MigrationManifest $manifest): ?TargetReconciliation;

    /**
     * Authenticate the operator's exact manifest+receipt authorization and retirement/activation proof, including applicable
     * provider inventory and namespace dispositions. At the exclusive admission boundary recheck the source
     * seal, attachment and live retention pin and consume the authorization idempotently. Serialize against
     * proof issuance for rollback. Return false on any refusal. No network inside a Billium transaction.
     */
    public function activate(MigrationManifest $manifest, TargetReconciliation $receipt, HandoffActivation $authorization): bool;

    /**
     * Permanently deny admission under this staged plan and drain the target before attesting no monetary
     * commit or external effect. After any first write/effect, return null. Serialize against activation and writes.
     */
    public function fenceForRollback(HandoffPlan $plan): ?TargetNoEffectsProof;
}
