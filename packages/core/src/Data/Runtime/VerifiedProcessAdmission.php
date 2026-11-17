<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class VerifiedProcessAdmission
{
    /** @param list<FinancialScope> $scopes */
    public function __construct(
        public FinancialAdmissionToken $token,
        public string $audience,
        public array $scopes,
        public string $issuerRef,
        public string $verificationDigest,
        public DateTimeImmutable $verifiedAt,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }
}
