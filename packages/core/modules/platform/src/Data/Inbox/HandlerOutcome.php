<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * What the owning handler did with one delivery inside the root Tx: applied with the business receipt it
 * wrote, parked on a missing dependency (the source order still advances), or refused as a conflict (quarantined).
 */
#[Api(since: '1.0')]
final readonly class HandlerOutcome
{
    private function __construct(
        public HandlerOutcomeKind $kind,
        public string $reference,
        public string $dependencyKind = '',
    ) {
        if ($reference === '' || strlen($reference) > 200 || strlen($dependencyKind) > 100) {
            throw new InvalidArgumentException('Handler outcome reference is a nonempty bounded string');
        }
    }

    public static function applied(string $receiptRef): self
    {
        return new self(HandlerOutcomeKind::Applied, $receiptRef);
    }

    public static function parkedDependency(string $kind, string $ref): self
    {
        if ($kind === '') {
            throw new InvalidArgumentException('A parked dependency names its kind');
        }

        return new self(HandlerOutcomeKind::ParkedDependency, $ref, $kind);
    }

    /** @param string $code a stable problem code such as billium.observation.conflict */
    public static function conflict(string $code): self
    {
        return new self(HandlerOutcomeKind::Conflict, $code);
    }
}
