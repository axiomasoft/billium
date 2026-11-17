<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class RetirementProof
{
    public function __construct(
        public string $proofRef,
        public OwnerSessionIdentity $oldOwner,
        public string $stoppedProducerManifestDigest,
        public string $terminatedWorkerManifestDigest,
        public string $revokedEgressProofDigest,
        public string $inFlightRequestManifestDigest,
        public string $issuerRef,
        public DateTimeImmutable $issuedAt,
        public string $signatureDigest,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }
}
