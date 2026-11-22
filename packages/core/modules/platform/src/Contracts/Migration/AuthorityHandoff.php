<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Migration;

use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\RetirementProof;
use Billium\Platform\Data\Migration\HandoffActivation;
use Billium\Platform\Data\Migration\HandoffPlan;
use Billium\Platform\Data\Migration\MigrationManifest;
use Billium\Platform\Data\Migration\SourceFreezeCertificate;
use Billium\Platform\Data\Migration\TargetReconciliation;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Privileged D58 handoff commands. Evidence failures require recovery or rebootstrap (D54/D63). */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface AuthorityHandoff
{
    public function stage(HandoffPlan $plan): void;

    public function freeze(HandoffPlan $plan): SourceFreezeCertificate;

    public function snapshot(SourceFreezeCertificate $freeze): MigrationManifest;

    public function verify(MigrationManifest $manifest): TargetReconciliation;

    public function activate(MigrationManifest $manifest, HandoffActivation $authorization): void;

    public function rollback(SourceFreezeCertificate $freeze, RetirementProof $retirement, ActivationCertificate $certificate): void;
}
