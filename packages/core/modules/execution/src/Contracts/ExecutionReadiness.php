<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Money;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Independently validate approved, versioned release and retention dossiers for this exact original scope,
 * source, merchant lineage, capability, action, currency and validity. References are selectors, never proof.
 * The host must pair this policy with WriterAdmission/DispatchEgress, including revocation at final egress.
 * This operational check replaces neither process authority nor provider qualification. Run only outside DB Tx.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ExecutionReadiness
{
    /** At startup, verify the selected release/retention dossiers and the configured qualification, authority and hard-fence integrations. No provider effect; missing or unverified integration refuses outgoing enablement. */
    public function assertConfigured(string $releaseEvidenceRef, string $retentionPolicyRef): void;

    public function assertReady(FrozenBinding $binding, EffectKind $kind, Money $amount, string $releaseEvidenceRef, string $retentionPolicyRef): void;
}
