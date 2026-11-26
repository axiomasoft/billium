<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Immutable authenticated host claim shape; constructing it is not verification.
 * requestDigest is sha256: plus SHA-256 of JSON encoded with JSON_THROW_ON_ERROR over the ordered list
 * [action, caller.installationRef, caller.actorRef, caller.sellerRef, caller.environment, caller.sourceAuthorities,
 * ...Installation method arguments except transitionRef]. Preserve sourceAuthorities order and argument types.
 * action is open, activate, sealing or seal. The host journal also binds the nonce to this exact transitionRef.
 */
#[Api(since: '1.0')]
final readonly class VerifiedSessionAuthorization
{
    public function __construct(
        public string $transitionRef,
        public string $action,
        public OwnerSessionIdentity $owner,
        public ?int $expectedLocalEpoch,
        public string $requestDigest,
        public string $evidenceRef,
        public ?ActivationCertificate $activationCertificate,
        public ?RetirementProof $retirementProof,
        public string $externalAdmissionRef,
        public string $issuerRef,
        public string $audience,
        public DateTimeImmutable $expiresAt,
        public string $nonce,
        public string $verificationDigest,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }
}
