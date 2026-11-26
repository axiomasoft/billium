<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Migration;

use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Platform\Data\Migration\HandoffPlan;
use Billium\Platform\Data\Migration\MigrationManifest;
use Billium\Platform\Data\Migration\SealedTail;
use Billium\Platform\Data\Migration\SourceFreezeCertificate;
use Billium\Platform\Data\Migration\TargetNoEffectsProof;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** D54/D58/D62/D63. Authenticated source authority external to restorable ledger state. Calls are at Tx0. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SourceHandoffHost
{
    /** Close every scoped mutator, seal admission and drain root transactions before returning an authenticated seal receipt. Idempotent by exact plan. */
    public function freeze(HandoffPlan $plan): SourceFreezeCertificate;

    /** Check signature, exact plan, deployment/session/epoch, generation, seal receipt and continued exclusion of every source writer. */
    public function isSealed(SourceFreezeCertificate $freeze): bool;

    /** Pin D52 retention before taking a REPEATABLE READ snapshot. Bind token, digest, lease and authenticated cursor to the exact scope/selection/catalog. */
    public function snapshot(SourceFreezeCertificate $freeze): MigrationManifest;

    /**
     * Authenticate the exact manifest, frozen lineage, snapshot bytes, active pin and safe D52 start and terminal cursors.
     * Return a seal only when the complete selected tail is retained and scanned, including empty selected pages
     * through the safe horizon. Observe partition gaps separately. Missing or superseded evidence returns null.
     */
    public function sealedTail(MigrationManifest $manifest): ?SealedTail;

    /** Verify the target proof against independent authority, then explicitly activate a new source session under D54. */
    public function resumeSource(SourceFreezeCertificate $freeze, TargetNoEffectsProof $target, RetirementProof $retirement, ActivationCertificate $certificate): bool;
}
