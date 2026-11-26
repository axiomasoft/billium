<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** References and authenticated continuity metadata only; secret material stays in the host resolver and must never be persisted or logged. */
#[Api(since: '1.0')]
final readonly class ResolvedCredentials
{
    public function __construct(
        public string $merchantAccountRef,
        public string $credentialVersionRef,
        public SecretRef $secretRef,
        public string $purpose,
        public DateTimeImmutable $expiresAt,
        public string $continuityProofRef,
    ) {
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if ($credentialVersionRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'credentialVersionRef must not be empty');
        }
        if ($purpose === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'purpose must not be empty');
        }
        if ($continuityProofRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'continuityProofRef must not be empty');
        }
    }
}
