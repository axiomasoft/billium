<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Support;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\RecordObligation;
use RuntimeException;

final class LedgerFault implements LedgerParticipants
{
    public int $calls = 0;

    /** @var list<TransactionContext> */
    public array $contexts = [];

    public function __construct(private readonly LedgerParticipants $inner, public string $failure = 'rollback') {}

    public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt
    {
        $this->calls++;
        $this->contexts[] = $context;
        $receipt = $this->inner->recordObligation(context: $context, command: $command);
        if ($this->failure === 'rollback') {
            throw new RuntimeException('test after successful ledger participant');
        }
        if ($this->failure === 'refusal') {
            throw new ProblemException(ProblemCode::PolicyUnknown, 'test ledger business refusal');
        }
        if ($this->failure === 'retry' && $this->calls === 1) {
            throw new LockPlanChanged('test retry after participant');
        }

        return $receipt;
    }

    public function declareSettlementIntent(TransactionContext $context, DeclareSettlementIntent $command): Receipt
    {
        return $this->inner->declareSettlementIntent($context, $command);
    }

    public function issueInvoice(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt
    {
        return $this->inner->issueInvoice($context, $command, $legalDocumentRef);
    }

    public function issueCreditNote(TransactionContext $context, IssueCreditNote $command): Receipt
    {
        return $this->inner->issueCreditNote($context, $command);
    }

    public function adjustObligation(TransactionContext $context, AdjustObligation $command): Receipt
    {
        return $this->inner->adjustObligation($context, $command);
    }

    public function closeSettlementIntentNoEffect(TransactionContext $context, CloseSettlementIntentNoEffect $command): Receipt
    {
        return $this->inner->closeSettlementIntentNoEffect($context, $command);
    }
}
