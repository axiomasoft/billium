<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use InvalidArgumentException;

/** A sanitized evidence reference, never a transport ACK or ledger mutation (D28/D41). */
#[Api]
final readonly class ProviderEvidence
{
    public function __construct(
        public string $evidenceRef,
        public string $status,
        public ?string $providerOperationId = null,
    ) {
        if ($evidenceRef === '' || ! in_array($status, ['accepted-unknown', 'pending', 'succeeded', 'failed-terminal', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid provider evidence');
        }
    }
}
