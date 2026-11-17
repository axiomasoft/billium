<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class OwnerSessionIdentity
{
    public function __construct(
        public string $installationRef,
        public string $ownerSessionRef,
        public int $deploymentGeneration,
        public int $attachmentGeneration,
        public int $authorityEpoch,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
        if ($deploymentGeneration < 1 || $attachmentGeneration < 1 || $authorityEpoch < 1) {
            throw new InvalidArgumentException('Owner generations and epoch start at one');
        }
    }
}
