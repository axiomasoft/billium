<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Shape only: the host authenticator is responsible for authenticated issuance. */
#[Api(since: '1.0')]
final readonly class SourceAuthorityGrant
{
    public function __construct(
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $sourceAuthority,
        public string $sourceLineage,
        public string $audience,
        public string $issuerRef,
        public DateTimeImmutable $expiresAt,
        public string $proofDigest,
    ) {
        foreach ([$installationRef, $sellerRef, $environment, $sourceAuthority, $sourceLineage, $audience, $issuerRef] as $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('Source authority grant identities must be bounded and nonempty');
            }
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $proofDigest) !== 1) {
            throw new InvalidArgumentException('Source authority grant requires a verification digest');
        }
    }
}
