<?php

declare(strict_types=1);

namespace Billium\Ledger\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\RecordObligation;

/**
 * Typed ledger participants of a root transaction owned by another module (D60, transaction-participants). Each method
 * receives the CURRENT TransactionContext, requires `ledger` in its admitted closure and the same runner/connection/
 * session, takes only predeclared locks through that context and never starts a runner; public Ledger mutations remain
 * root-only. No method exposes SQL, a connection or foreign tables. Implementation and proofs: P3.8.
 */
#[Api]
#[Spi(SpiKind::Call)]
interface LedgerParticipants
{
    /** Also the positive finalized correction path, with a basisRef derived from the original basis and correction identity. */
    public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt;

    public function declareSettlementIntent(TransactionContext $context, DeclareSettlementIntent $command): Receipt;

    public function issueInvoice(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt;

    public function issueCreditNote(TransactionContext $context, IssueCreditNote $command): Receipt;

    public function adjustObligation(TransactionContext $context, AdjustObligation $command): Receipt;

    /**
     * Appends a no-effect closure revision (D63/F04). Refuses missing proof, a stale/foreign context, a committed
     * dispatch permit, a pending/unknown external effect or an existing economic application.
     */
    public function closeSettlementIntentNoEffect(TransactionContext $context, CloseSettlementIntentNoEffect $command): Receipt;
}
