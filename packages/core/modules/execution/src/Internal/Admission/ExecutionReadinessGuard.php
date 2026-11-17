<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Closure;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ExecutionReadinessGuard
{
    /** @param Closure():?ExecutionReadiness $verifier */
    public function __construct(private BilliumOptions $options, private TransportBoundary $boundary, private Closure $verifier) {}

    public function assertReady(FrozenBinding $binding, EffectKind $kind, Money $amount): void
    {
        $this->boundary->assertOutsideTransaction();
        $inputs = $this->options->execution;
        if (! $inputs->outgoingEnabled) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.execution.outgoing_enabled is false; only retained recovery and read paths are available');
        }
        if ($inputs->releaseEvidenceRef === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.execution.release_evidence_ref is required for a new outgoing effect');
        }
        if ($inputs->retentionPolicyRef === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.execution.retention_policy_ref is required for a new outgoing effect');
        }
        $verifier = ($this->verifier)() ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'ExecutionReadiness host verification is not configured');
        $verifier->assertReady($binding, $kind, $amount, $inputs->releaseEvidenceRef, $inputs->retentionPolicyRef);
    }
}
