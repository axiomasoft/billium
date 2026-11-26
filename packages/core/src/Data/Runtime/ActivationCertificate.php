<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class ActivationCertificate
{
    public function __construct(
        public string $certificateRef,
        public OwnerSessionIdentity $newOwner,
        public int $localEpoch,
        public string $retirementProofDigest,
        public RecoveryCoverage $coverage,
        public string $actorRef,
        public string $verdict,
        public string $reason,
        public DateTimeImmutable $expiresAt,
        public string $nonce,
        public string $signatureDigest,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }
}
