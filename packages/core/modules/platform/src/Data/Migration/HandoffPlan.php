<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Contracts\Contracts\Codec;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class HandoffPlan
{
    public function __construct(public string $handoffRef, public LedgerScope $source, public LedgerScope $target)
    {
        if (trim($handoffRef) === '' || strlen($handoffRef) > 200
            || ($source->owner->installationRef === $target->owner->installationRef && $target->owner->authorityEpoch <= $source->owner->authorityEpoch)
            || $source->feed->environment !== $target->feed->environment
            || $source->feed->sellerRef !== $target->feed->sellerRef) {
            throw new InvalidArgumentException('Handoff requires distinct owners in the same seller and environment');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['handoffRef' => $this->handoffRef, 'source' => $this->source->toArray(), 'target' => $this->target->toArray()];
    }

    public function digest(): string
    {
        return (new Codec)->digest($this->toArray());
    }
}
