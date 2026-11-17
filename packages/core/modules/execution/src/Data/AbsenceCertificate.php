<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Shape only. ProviderAbsenceVerifier must authenticate complete original namespace coverage and retirement/egress proofs outside Tx. A constructed certificate never proves absence. */
#[Api(since: '1.0')]
final readonly class AbsenceCertificate
{
    public function __construct(
        public string $certificateRef,
        public FrozenBinding $binding,
        public string $providerReference,
        public string $providerNamespace,
        public string $providerCompletenessWatermark,
        public DateTimeImmutable $coverageFrom,
        public DateTimeImmutable $coverageThrough,
        public string $closedDispatchManifestDigest,
        public string $retirementProofDigest,
        public string $egressFenceRef,
        public string $qualificationEvidenceRef,
        public string $issuerRef,
        public string $signatureDigest,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($certificateRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'certificateRef must not be empty');
        }
        if ($providerReference === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerReference must not be empty');
        }
        if ($providerNamespace === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerNamespace must not be empty');
        }
        if ($providerCompletenessWatermark === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'providerCompletenessWatermark must not be empty');
        }
        if ($closedDispatchManifestDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'closedDispatchManifestDigest must not be empty');
        }
        if ($retirementProofDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'retirementProofDigest must not be empty');
        }
        if ($egressFenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'egressFenceRef must not be empty');
        }
        if ($qualificationEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'qualificationEvidenceRef must not be empty');
        }
        if ($issuerRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'issuerRef must not be empty');
        }
        if ($signatureDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'signatureDigest must not be empty');
        }
        if ($coverageThrough < $coverageFrom) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Absence coverage interval is reversed');
        }
    }
}
