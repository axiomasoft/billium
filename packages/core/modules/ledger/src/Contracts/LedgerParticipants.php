<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Typed ledger participants of a root transaction owned by another module (D60, transaction-participants). Each method
 * receives the CURRENT TransactionContext, requires `ledger` in its admitted closure and the same runner/connection/
 * session, takes only predeclared locks through that context and never starts a runner; public Ledger mutations remain
 * root-only. No method exposes SQL, a connection or foreign tables. Record/adjust/declare implementation: P3.6; issueInvoice: P3.7; credit note and closure proof boundary: P3.8.
 * P4 corrections (D63/F01): a draft obligation is adjusted (adjustObligation refuses a frozen invoice); a finalized one
 * gets a positive delta as recordObligation with CorrectionBasis::derive(original basis, correction identity, revision)
 * and a negative delta as issueCreditNote. The original obligation is never overwritten.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface LedgerParticipants
{
    /** Also the positive finalized correction path, with a basisRef derived from the original basis and correction identity. */
    public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt;

    public function declareSettlementIntent(TransactionContext $context, DeclareSettlementIntent $command): Receipt;

    /** Issues the draft and sets legal_document_ref once, in the same root Tx as the P6 numbering (D49); the locks must be planned by the root. */
    public function issueInvoice(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt;

    public function issueCreditNote(TransactionContext $context, IssueCreditNote $command): Receipt;

    public function adjustObligation(TransactionContext $context, AdjustObligation $command): Receipt;

    /**
     * Appends a no-effect closure revision (D63/F04). Refuses missing proof, a stale/foreign context or proof of another
     * root, a committed dispatch permit, a pending/unknown/sent external effect, a stale revision or an existing
     * economic application. Exact replay returns the saved closure receipt.
     */
    public function closeSettlementIntentNoEffect(TransactionContext $context, CloseSettlementIntentNoEffect $command): Receipt;
}
