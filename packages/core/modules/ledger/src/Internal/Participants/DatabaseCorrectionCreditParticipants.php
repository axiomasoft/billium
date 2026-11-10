<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Participants;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Ledger\Contracts\CorrectionCreditParticipants;
use Billium\Ledger\Internal\Commands\AllocateLockPlan;
use Billium\Ledger\Internal\Commands\IssueCreditNoteCommand;

final readonly class DatabaseCorrectionCreditParticipants implements CorrectionCreditParticipants
{
    public function __construct(private IssueCreditNoteCommand $creditNotes) {}

    public function issue(TransactionContext $context, array $commands): void
    {
        if ($commands === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'correction requires credit notes');
        }
        $states = [];
        $intents = $obligations = $invoices = $payments = $credits = $refs = [];
        $scope = null;
        foreach ($commands as $command) {
            if (isset($refs[$command->invoiceRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'correction requires distinct invoice credit notes');
            }
            $refs[$command->invoiceRef] = true;
            $identity = [$command->scope->sellerRef, $command->scope->environment, $command->scope->billingAccountRef, $command->mode->value];
            if ($scope !== null && $scope !== $identity) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'correction credit scope differs');
            }
            $scope = $identity;
            $state = $this->creditNotes->correctionPlan($context, $command);
            $states[] = $state;
            array_push($intents, ...$state['intents']);
            array_push($obligations, ...array_values($state['obligations']));
            array_push($invoices, $state['invoice'], ...$state['invoices']);
            array_push($payments, ...$state['payments']);
            array_push($credits, ...$state['credits']);
        }
        (new AllocateLockPlan(intents: $intents, obligations: $obligations, invoices: $invoices, payments: $payments, credits: $credits))->lock($context);
        foreach ($commands as $index => $command) {
            if ($this->creditNotes->correctionPlan($context, $command) !== $states[$index]) {
                throw new LockPlanChanged('correction credit plan changed');
            }
        }
        foreach ($commands as $command) {
            $this->creditNotes->execute($context, $command);
        }
    }
}
