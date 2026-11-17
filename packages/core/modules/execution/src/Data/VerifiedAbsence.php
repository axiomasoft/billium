<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Minted by the independently configured verifier before entering the financial transaction. */
#[Api(since: '1.0')]
final readonly class VerifiedAbsence
{
    private function __construct(public AbsenceCertificate $certificate, public string $attemptRef, public string $operationRef) {}

    public static function verify(ProviderAbsenceVerifier $verifier, AbsenceCertificate $certificate, OperationRecord $operation, AttemptRecord $attempt, CapabilitySnapshot $capability): self
    {
        $now = new DateTimeImmutable;
        if (json_encode($certificate->binding, JSON_THROW_ON_ERROR) !== json_encode($operation->binding, JSON_THROW_ON_ERROR) || $attempt->operationRef !== $operation->operationRef
            || $certificate->providerReference !== $attempt->providerReference || ! $capability->absenceCertification
            || $operation->binding->capabilityDigest !== $capability->digest || $operation->binding->merchantAccountRef !== $capability->merchantAccountRef
            || $certificate->providerNamespace !== $capability->details->lookupNamespace || $certificate->qualificationEvidenceRef !== $capability->qualificationEvidenceRef
            || $attempt->startedAt === null || $attempt->requestDeadlineAt === null || $certificate->coverageFrom > $attempt->startedAt
            || $certificate->coverageThrough < $attempt->requestDeadlineAt || $certificate->coverageThrough > $now || $certificate->expiresAt <= $now) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Absence proof does not cover the original attempt');
        }
        $verifier->verify($certificate, $operation->binding);

        return new self($certificate, $attempt->attemptRef, $operation->operationRef);
    }
}
