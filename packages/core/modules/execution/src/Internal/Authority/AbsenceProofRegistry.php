<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Authority;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Closure;
use Packman\Attributes\Internal;
use WeakMap;

#[Internal]
final readonly class AbsenceProofRegistry
{
    /** @var WeakMap<VerifiedAbsence,true> */
    private WeakMap $issued;

    /** @param Closure():?ProviderAbsenceVerifier $verifier */
    public function __construct(private TransportBoundary $boundary, private Closure $verifier)
    {
        $this->issued = new WeakMap;
    }

    public function verify(AbsenceCertificate $certificate, OperationRecord $operation, AttemptRecord $attempt, CapabilitySnapshot $capability): VerifiedAbsence
    {
        $this->boundary->assertOutsideTransaction();
        $verifier = ($this->verifier)() ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Configured absence verifier unavailable');
        $proof = VerifiedAbsence::verify($verifier, $certificate, $operation, $attempt, $capability);
        $this->issued[$proof] = true;

        return $proof;
    }

    public function assertIssued(VerifiedAbsence $proof): void
    {
        if (! isset($this->issued[$proof])) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Absence proof was not issued by the configured verifier');
        }
    }
}
