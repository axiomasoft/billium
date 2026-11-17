<?php

declare(strict_types=1);

namespace Billium\Core\Data\Runtime;

use InvalidArgumentException;
use Packman\Attributes\Api;

/** Immutable claim shape only. Authentication belongs to the host evidence SPI, never to this constructor. */
#[Api(since: '1.0')]
final readonly class UnresolvedDisposition
{
    public function __construct(
        public string $operationRef,
        public string $merchantAccountRef,
        public string $state,
        public string $permittedDisposition,
        public ?string $heldReservationRef,
        public string $reason,
    ) {
        foreach (get_object_vars($this) as $claim) {
            if (is_string($claim) && trim($claim) === '') {
                throw new InvalidArgumentException('Authority claims must be nonempty');
            }
        }
    }
}
