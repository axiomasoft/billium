<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Data\StoredOutcome;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\Allocate;
use Billium\Ledger\Data\AmendObligationDuePolicy;
use Billium\Ledger\Data\CreateInvoice;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\DiscardInvoice;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\MarkUncollectible;
use Billium\Ledger\Data\ReallocatePayment;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Data\ReverseAllocation;
use Billium\Ledger\Data\UpdateInvoiceMetadata;
use Billium\Ledger\Data\VoidInvoice;

/** Root-only financial commands (D60). Composition uses LedgerParticipants in the existing root. */
#[Api]
interface Ledger
{
    public function recordObligation(RecordObligation $command): StoredOutcome;

    public function adjustObligation(AdjustObligation $command): StoredOutcome;

    public function amendObligationDuePolicy(AmendObligationDuePolicy $command): StoredOutcome;

    public function recordCustomerCredit(RecordCustomerCredit $command): StoredOutcome;

    public function declareSettlementIntent(DeclareSettlementIntent $command): StoredOutcome;

    /** Draft over unbilled obligations (D49: no legal number). */
    public function createInvoice(CreateInvoice $command): StoredOutcome;

    /** Allow-list keys only (note, external_ref, labels); after issue anything else is billium.invoice.frozen (INV-13). */
    public function updateInvoiceMetadata(UpdateInvoiceMetadata $command): StoredOutcome;

    /** Root-only issue without a legal number; composed issue with numbering is LedgerParticipants::issueInvoice (D60). */
    public function issueInvoice(IssueInvoice $command): StoredOutcome;

    public function discardInvoice(DiscardInvoice $command): StoredOutcome;

    public function voidInvoice(VoidInvoice $command): StoredOutcome;

    public function markUncollectible(MarkUncollectible $command): StoredOutcome;

    /** One funds source → one obligation; AllocationReceipt, allocation.applied.v1 (INV-09). No PSP call. */
    public function allocate(Allocate $command): StoredOutcome;

    /** Full immutable reversal of an original allocation, optionally placing a remainder again (COV-289). */
    public function reverseAllocation(ReverseAllocation $command): StoredOutcome;

    /** open_only or reverse_allocations credit note on an issued invoice; credit_note.issued.v1. */
    public function issueCreditNote(IssueCreditNote $command): StoredOutcome;

    /** allocation.reallocate.v1 after a confirmed refund (D35); ReallocatePayment::fromWire decodes the wire form. */
    public function reallocatePayment(ReallocatePayment $command): StoredOutcome;
}
