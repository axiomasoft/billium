<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\DiscardInvoice;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\MarkUncollectible;
use Billium\Ledger\Data\Invoices\UpdateInvoiceMetadata;
use Billium\Ledger\Data\Invoices\VoidInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\AmendObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Packman\Attributes\Api;

/** Root-only financial commands (D60). Composition uses LedgerParticipants in the existing root. */
#[Api(since: '1.0')]
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
