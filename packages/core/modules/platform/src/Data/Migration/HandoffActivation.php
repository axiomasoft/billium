<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Core\Data\Runtime\ActivationCertificate;
use Billium\Core\Data\Runtime\RetirementProof;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Claim shape. The host authenticates the exact manifest and receipt binding as part of authorization. */
#[Api(since: '1.0')]
final readonly class HandoffActivation
{
    public function __construct(
        public string $manifestDigest,
        public string $targetReceiptDigest,
        public RetirementProof $retirement,
        public ActivationCertificate $certificate,
    ) {
        if (! MigrationDigest::valid($manifestDigest) || ! MigrationDigest::valid($targetReceiptDigest)) {
            throw new InvalidArgumentException('Activation requires an exact manifest and target receipt');
        }
    }
}
