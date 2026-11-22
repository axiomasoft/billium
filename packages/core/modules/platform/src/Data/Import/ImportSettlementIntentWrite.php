<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Typed participant payload for declaring a proven settlement intent before observation reduction.
 */
#[Api(since: '1.0')]
final readonly class ImportSettlementIntentWrite
{
    /**
     * @param  list<string>  $obligationRefs
     */
    public function __construct(
        public string $commandKey,
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $businessIntentRef,
        public string $currency,
        public array $obligationRefs,
        public ?int $requestedMinor = null,
    ) {}
}
