<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Participants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LockedInvoiceSnapshotParticipant;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Data\Views\LedgerView;
use Billium\Ledger\Internal\Commands\AdjustObligationCommand;
use Billium\Ledger\Internal\Commands\AllocateLockPlan;
use Billium\Ledger\Internal\Commands\DeclareSettlementIntentCommand;
use Billium\Ledger\Internal\Commands\IssueCreditNoteCommand;
use Billium\Ledger\Internal\Commands\IssueInvoiceCommand;
use Billium\Ledger\Internal\Commands\RecordObligationCommand;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseLedgerParticipants implements LedgerParticipants, LockedInvoiceSnapshotParticipant
{
    public function __construct(
        private OperationRunner $runner,
        private RecordObligationCommand $obligations,
        private AdjustObligationCommand $adjustments,
        private DeclareSettlementIntentCommand $intents,
        private IssueInvoiceCommand $invoiceIssues,
        private IssueCreditNoteCommand $creditNotes,
        private DatabaseManager $databases,
        private LedgerRepository $routines,
    ) {}

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
        return $this->invoiceIssues->execute($context, $command, $legalDocumentRef);
    }

    public function lockedInvoiceSnapshot(TransactionContext $context, BillingAccountScope $scope, string $invoiceRef): array
    {
        $this->runner->assertCurrent($context, 'ledger');
        $caller = $context->caller();
        if ($caller->sellerRef !== $scope->sellerRef || $caller->environment !== $scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        if ($invoiceRef === '' || strlen($invoiceRef) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'references are bounded strings');
        }

        $db = $this->databases->connection($context->connectionName());
        $invoice = $db->table('billium_invoice')
            ->where('installation_ref', $caller->installationRef)
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('billing_account_ref', $scope->billingAccountRef)
            ->where('invoice_ref', $invoiceRef)
            ->first(['id', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor']);

        if ($invoice === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown invoice');
        }

        $lineRows = $db->table('billium_invoice_line as l')
            ->join('billium_obligation as o', static function (JoinClause $join): void {
                $join->on('o.id', '=', 'l.obligation_id')
                    ->on('o.installation_ref', '=', 'l.installation_ref')
                    ->on('o.seller_ref', '=', 'l.seller_ref')
                    ->on('o.environment', '=', 'l.environment');
            })
            ->where('l.installation_ref', $caller->installationRef)
            ->where('l.seller_ref', $caller->sellerRef)
            ->where('l.environment', $caller->environment)
            ->where('l.invoice_id', $invoice->id)
            ->orderBy('l.position')
            ->get(['o.obligation_ref', 'l.subtotal_minor', 'l.tax_minor', 'l.amount_minor']);

        $lines = [];
        foreach ($lineRows as $lineRow) {
            $lines[] = [
                'obligationRef' => LedgerView::text($lineRow->obligation_ref),
                'subtotalMinor' => LedgerView::integer($lineRow->subtotal_minor),
                'taxMinor' => LedgerView::integer($lineRow->tax_minor),
                'totalMinor' => LedgerView::integer($lineRow->amount_minor),
            ];
        }

        return [
            'currency' => LedgerView::text($invoice->currency),
            'subtotalMinor' => LedgerView::integer($invoice->subtotal_minor),
            'taxMinor' => LedgerView::integer($invoice->tax_minor),
            'totalMinor' => LedgerView::integer($invoice->total_minor),
            'lines' => $lines,
        ];
    }

    public function issueCreditNote(TransactionContext $context, IssueCreditNote $command): Receipt
    {
        return $this->creditNotes->execute($context, $command);
    }

    public function closeSettlementIntentNoEffect(TransactionContext $context, CloseSettlementIntentNoEffect $command): Receipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        $this->routines->assertMonetary($context);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        foreach ([$command->scope->billingAccountRef, $command->settlementIntentRef, $command->stepAuthorizationRef, $command->closureRef, $command->cause] as $value) {
            if (strlen($value) > 200) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'references are bounded strings');
            }
        }
        // FA02 typed boundary: only a proof minted for THIS root by the collection owner, for this intent revision and step.
        $proof = $command->proof ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'a no-effect closure requires a typed NoEffectProof');
        if ($proof->rootRef !== $context->rootRef()) {
            throw new LogicException('NoEffectProof belongs to another root transaction (D60, FA02)');
        }
        if ($proof->settlementIntentRef !== $command->settlementIntentRef || $proof->expectedRevision !== $command->expectedRevision
            || $proof->stepAuthorizationRef !== $command->stepAuthorizationRef) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'the proof covers another intent revision or step');
        }
        // A committed dispatch permit or any pending/unknown/sent effect is never closed as no-effect, not even by timeout.
        if ($proof->dispatchPermitCommitted || $proof->externalEffect !== 'none') {
            throw new ProblemException(ProblemCode::OperationNotCancellable, 'the collection step may have an external effect');
        }
        $connection = $this->databases->connection($context->connectionName());
        $query = $connection->table('billium_settlement_intent')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
            ->where('business_intent_ref', $command->settlementIntentRef);
        $intent = $query->value('id');
        if (! is_int($intent)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown settlement intent');
        }
        // SettlementIntent rank 65 before any obligation/funds lock of this root (FA02).
        new AllocateLockPlan([$intent])->lock($context);
        if ($query->value('id') !== $intent) {
            throw new LockPlanChanged('settlement intent changed');
        }

        return $this->routines->call($connection, 'close_settlement_intent_no_effect', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $command->scope->billingAccountRef, $command->currency, $intent,
            $command->closureRef, $command->expectedRevision, $command->stepAuthorizationRef, $proof->kind, $proof->digest(), $proof->evidenceRef,
            $command->cause, 'intent-closure:'.$command->closureRef,
        ], 'ledger.settlement_intent_closure');
    }
}
