<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Shape only. WriterAdmission verifies authenticity outside Tx and the egress host enforces revocation at the actual send boundary. */
#[Api(since: '1.0')]
final readonly class DispatchTicket
{
    public function __construct(
        public string $ticketRef,
        public DispatchRequest $claims,
        public string $issuerRef,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public string $nonce,
        public string $egressFenceRef,
        public string $signatureDigest,
    ) {
        if ($ticketRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'ticketRef must not be empty');
        }
        if ($issuerRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'issuerRef must not be empty');
        }
        if ($nonce === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'nonce must not be empty');
        }
        if ($egressFenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'egressFenceRef must not be empty');
        }
        if ($signatureDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'signatureDigest must not be empty');
        }
    }
}
