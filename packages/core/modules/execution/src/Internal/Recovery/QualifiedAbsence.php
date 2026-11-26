<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Recovery;

use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Data\AbsenceCertificate;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Exception;
use Packman\Attributes\Internal;

#[Internal]
final readonly class QualifiedAbsence
{
    public function __construct(private TransportBoundary $boundary, private ?ProviderAbsenceVerifier $verifier = null) {}

    public function valid(?AbsenceCertificate $certificate, OperationRecord $operation, AttemptRecord $attempt, CapabilitySnapshot $capability): bool
    {
        $now = new DateTimeImmutable;
        if ($certificate === null || $this->verifier === null || ! $capability->absenceCertification
            || ExecutionCodec::differs($certificate->binding, $operation->binding) || $certificate->providerReference !== $attempt->providerReference
            || $certificate->providerNamespace !== $capability->details->lookupNamespace || $certificate->qualificationEvidenceRef !== $capability->qualificationEvidenceRef
            || $attempt->startedAt === null || $certificate->coverageFrom > $attempt->startedAt || $attempt->requestDeadlineAt === null || $certificate->coverageThrough < $attempt->requestDeadlineAt
            || $certificate->expiresAt <= $now || $certificate->coverageThrough > $now) {
            return false;
        }
        $this->boundary->assertOutsideTransaction();
        try {
            $this->verifier->verify($certificate, $operation->binding);
        } catch (Exception) {
            return false;
        }

        return true;
    }
}
