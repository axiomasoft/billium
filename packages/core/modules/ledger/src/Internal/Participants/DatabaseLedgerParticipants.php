<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Participants;

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\RecordObligation;
use Billium\Ledger\Internal\Commands\AdjustObligationCommand;
use Billium\Ledger\Internal\Commands\DeclareSettlementIntentCommand;
use Billium\Ledger\Internal\Commands\RecordObligationCommand;
use LogicException;

final readonly class DatabaseLedgerParticipants implements LedgerParticipants
{
    public function __construct(private OperationRunner $runner, private RecordObligationCommand $obligations, private AdjustObligationCommand $adjustments, private DeclareSettlementIntentCommand $intents) {}

    public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt
    {
        return $this->obligations->execute($context, $command);
    }

    public function adjustObligation(TransactionContext $context, AdjustObligation $command): Receipt
    {
        return $this->adjustments->execute($context, $command);
    }

    public function declareSettlementIntent(TransactionContext $context, DeclareSettlementIntent $command): Receipt
    {
        return $this->intents->execute($context, $command);
    }

    public function issueInvoice(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        throw new LogicException('Invoice participant belongs to P3.7');
    }

    public function issueCreditNote(TransactionContext $context, IssueCreditNote $command): Receipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        throw new LogicException('Credit note participant belongs to P3.8');
    }

    public function closeSettlementIntentNoEffect(TransactionContext $context, CloseSettlementIntentNoEffect $command): Receipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        throw new LogicException('Typed no-effect proof participant belongs to P3.8 (FA02)');
    }
}
