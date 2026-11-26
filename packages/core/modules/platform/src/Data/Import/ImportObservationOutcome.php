<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Read-only lookup result of an observed payment/refund reduction and its AllocationReceipt for receipt adoption.
 */
#[Api(since: '1.0')]
final readonly class ImportObservationOutcome
{
    public const string STATUS_ABSENT = 'absent';

    public const string STATUS_APPLIED = 'applied';

    public const string STATUS_PARKED = 'parked';

    public const string STATUS_HELD_DISCREPANCY = 'held_discrepancy';

    /**
     * @param  array<string, mixed>  $receiptBody
     */
    public function __construct(
        public string $status,
        public ?string $receiptKind = null,
        public ?string $receiptRef = null,
        public ?string $projectionStatus = null,
        public array $receiptBody = [],
    ) {}
}
