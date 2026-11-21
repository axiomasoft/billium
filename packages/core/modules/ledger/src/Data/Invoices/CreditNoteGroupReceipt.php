<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Packman\Attributes\Api;

/**
 * `creditNoteRef` names the whole credit: the issued note itself for one invoice, otherwise a group reference that
 * LedgerQueries::receipt('credit-note:'.$creditNoteRef) resolves to every part.
 */
#[Api(since: '1.0')]
final readonly class CreditNoteGroupReceipt
{
    /** @param non-empty-list<string> $partRefs */
    public function __construct(public string $creditNoteRef, public array $partRefs) {}
}
