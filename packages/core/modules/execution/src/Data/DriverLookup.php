<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use DateTimeImmutable;
use InvalidArgumentException;

/** Read-only query covers the full possible dispatch interval in the original binding. */
#[Api]
final readonly class DriverLookup
{
    public function __construct(
        public ExecutionBinding $binding,
        public string $selectorKind,
        public string $selectorValue,
        public DateTimeImmutable $dispatchFrom,
        public DateTimeImmutable $dispatchThrough,
    ) {
        if (! in_array($selectorKind, ['provider_id', 'business_ref', 'refund_ref', 'event_id'], true)
            || $selectorValue === '' || $dispatchFrom > $dispatchThrough) {
            throw new InvalidArgumentException('Invalid lookup coverage');
        }
    }
}
