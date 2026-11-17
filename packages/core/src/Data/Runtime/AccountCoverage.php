<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class AccountCoverage
{
    public function __construct(
        public string $merchantAccountRef,
        public string $providerNamespace,
        public DateTimeImmutable $from,
        public DateTimeImmutable $through,
        public string $completenessWatermark,
        public string $inventoryDigest,
        public string $qualificationEvidenceRef,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
        if ($from > $through) {
            throw new InvalidArgumentException('Coverage interval must be ordered');
        }
    }
}
