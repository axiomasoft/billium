<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** D54: claim that the exact target has no monetary commits or effects and can no longer acquire write admission. */
#[Api(since: '1.0')]
final readonly class TargetNoEffectsProof
{
    public function __construct(public string $planDigest, public string $targetSealReceiptRef, public string $signatureDigest)
    {
        if (! MigrationDigest::valid($planDigest) || trim($targetSealReceiptRef) === '' || ! MigrationDigest::valid($signatureDigest)) {
            throw new InvalidArgumentException('Incomplete target no-effects proof');
        }
    }
}
