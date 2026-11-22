<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use Packman\Attributes\Api;

/**
 * Typed participant payload for issuing a linked credit note on an issued invoice without PSP dispatch.
 */
#[Api(since: '1.0')]
final readonly class ImportCreditNoteWrite
{
    /**
     * @param  list<array{obligationRef: string, money: Money, componentRef?: string, taxSnapshotRef?: string}>  $lines
     */
    public function __construct(
        public string $commandKey,
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $creditNoteRef,
        public string $invoiceRef,
        public string $mode,
        public array $lines,
        public string $reason,
    ) {}
}
