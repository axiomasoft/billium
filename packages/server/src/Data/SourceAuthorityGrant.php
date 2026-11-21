<?php

declare(strict_types=1);

namespace Billium\Server\Data;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Packman\Attributes\Api;

/** Authorizes ordinary intake on one exact lineage and a set of partitions. Bootstrap requires separate recovery authorization. */
#[Api(since: '1.0')]
final readonly class SourceAuthorityGrant
{
    /** @param list<string> $partitionRefs */
    public function __construct(
        public string $channel,
        public string $sourceAuthority,
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $sourceLineage,
        public array $partitionRefs,
    ) {}

    public function coversObservation(PaymentObservation $observation): bool
    {
        return $this->channel === 'payment.observation'
            && $this->installationRef === $observation->installationRef
            && $this->environment === $observation->environment
            && $this->sellerRef === $observation->sellerRef
            && $this->sourceAuthority === $observation->sourceAuthority
            && $this->sourceLineage === $observation->sourceLineage
            && in_array($observation->partitionRef, $this->partitionRefs, true);
    }
}
