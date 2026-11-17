<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Participants;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Schedule\Contracts\OccurrenceParticipants;
use Billium\Schedule\Data\Commands\AdvanceOccurrence;
use Billium\Schedule\Data\OccurrenceReceipt;
use Billium\Schedule\Enums\OccurrenceState;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use Billium\Schedule\Internal\Locks\ScheduleLockRequests;
use Billium\Schedule\Internal\Storage\OccurrenceStore;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseOccurrenceParticipants implements OccurrenceParticipants
{
    public function __construct(private OperationRunner $runner, private ScheduleLockRequests $locks, private OccurrenceStore $store) {}

    public function advanceOccurrence(TransactionContext $context, AdvanceOccurrence $command): OccurrenceReceipt
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
        $this->locks->assertHeld(context: $context, ref: $command->occurrenceRef);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->sellerRef || $caller->environment !== $command->environment) {
            OccurrenceProblems::refuse('occurrence.scope_denied');
        }
        $row = $this->store->occurrence(ref: $command->occurrenceRef, context: $context);
        if ($row === null || ! in_array($command->to, [OccurrenceState::Rated, OccurrenceState::Obligated], true)
            || $command->ratedBasisRef === null || $command->ratedBasisRef === '' || strlen($command->ratedBasisRef) > 200
            || ($row->state !== 'planned' && ($row->state !== 'rated' || $command->to !== OccurrenceState::Obligated))
            || ($row->state === 'rated' && $row->rated_basis_ref !== $command->ratedBasisRef)) {
            OccurrenceProblems::refuse('occurrence.transition_forbidden');
        }
        // The outer root owns the rating/obligation write and its receipt/outbox (D60, FA03).
        $this->store->query(table: 'billing_occurrence', context: $context)->where('id', $row->id)->update([
            'state' => $command->to->value, 'rated_basis_ref' => $command->ratedBasisRef,
            'row_version' => $this->store->database($context)->raw('row_version+1')]);
        $row->state = $command->to->value;

        return $this->store->receipt(row: $row, created: false);
    }
}
