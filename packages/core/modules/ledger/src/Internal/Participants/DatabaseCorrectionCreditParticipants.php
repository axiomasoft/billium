<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Participants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Data\Invoices\CreditNoteGroupReceipt;
use Billium\Ledger\Data\Invoices\CreditNotePart;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueCreditNoteGroup;
use Billium\Ledger\Internal\Commands\AllocateLockPlan;
use Billium\Ledger\Internal\Commands\CreditNoteGroupRef;
use Billium\Ledger\Internal\Commands\IssueCreditNoteCommand;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseCorrectionCreditParticipants implements CorrectionCreditParticipants
{
    public function __construct(private IssueCreditNoteCommand $creditNotes) {}

    #[Override]
    public function issue(TransactionContext $context, IssueCreditNoteGroup $group): CreditNoteGroupReceipt
    {
        if ($group->parts === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'correction requires credit notes');
        }
        $parts = $group->parts;
        usort($parts, static fn (CreditNotePart $a, CreditNotePart $b): int => strcmp($a->invoiceRef, $b->invoiceRef));
        $invoiceRefs = array_map(static fn (CreditNotePart $part): string => $part->invoiceRef, $parts);
        if (count($invoiceRefs) !== count(array_unique($invoiceRefs))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'correction requires distinct invoice credit notes');
        }
        if (count($parts) === 1) {
            $this->creditNotes->execute($context, self::command($group, $parts[0], $group->creditNoteRef));

            return new CreditNoteGroupReceipt(creditNoteRef: $group->creditNoteRef, partRefs: [$group->creditNoteRef]);
        }
        $commands = array_map(static fn (CreditNotePart $part): IssueCreditNote => self::command($group, $part,
            CreditNoteGroupRef::part($group->creditNoteRef, $part->invoiceRef)), $parts);
        $states = [];
        $intents = $obligations = $invoices = $payments = $credits = [];
        foreach ($commands as $command) {
            $state = $this->creditNotes->correctionPlan($context, $command);
            $states[] = $state;
            array_push($intents, ...$state['intents']);
            array_push($obligations, ...array_values($state['obligations']));
            array_push($invoices, $state['invoice'], ...$state['invoices']);
            array_push($payments, ...$state['payments']);
            array_push($credits, ...$state['credits']);
        }
        new AllocateLockPlan(intents: $intents, obligations: $obligations, invoices: $invoices, payments: $payments, credits: $credits)->lock($context);
        foreach ($commands as $index => $command) {
            if ($this->creditNotes->correctionPlan($context, $command) !== $states[$index]) {
                throw new LockPlanChanged('correction credit plan changed');
            }
        }
        foreach ($commands as $command) {
            $this->creditNotes->execute($context, $command);
        }

        return new CreditNoteGroupReceipt(creditNoteRef: CreditNoteGroupRef::group($group->creditNoteRef, $invoiceRefs),
            partRefs: array_map(static fn (IssueCreditNote $command): string => $command->creditNoteRef, $commands));
    }

    private static function command(IssueCreditNoteGroup $group, CreditNotePart $part, string $ref): IssueCreditNote
    {
        return new IssueCreditNote(commandKey: $ref, scope: $group->scope, creditNoteRef: $ref, invoiceRef: $part->invoiceRef,
            mode: $group->mode, lines: $part->lines, reason: $group->reason);
    }
}
