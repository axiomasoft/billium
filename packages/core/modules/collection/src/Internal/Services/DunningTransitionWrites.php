<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Illuminate\Database\Connection;
use Packman\Attributes\Internal;

#[Internal]
final class DunningTransitionWrites
{
    /** @param array<string, mixed> $values */
    public static function updateStepCas(Connection $db, CollectionStepView $step, array $values): void
    {
        $updated = $db->table('billium_collection_step')
            ->where('id', $step->id)
            ->where('step_revision', $step->stepRevision)
            ->update($values);

        if ($updated !== 1) {
            throw new LockPlanChanged('Collection step transition CAS failed');
        }
    }

    /** @param array<string, mixed> $values */
    public static function updateCaseCas(Connection $db, DunningCaseView $case, array $values): void
    {
        $updated = $db->table('billium_collection_dunning_case')
            ->where('id', $case->id)
            ->where('case_revision', $case->caseRevision)
            ->update($values);

        if ($updated !== 1) {
            throw new LockPlanChanged('Dunning case transition CAS failed');
        }
    }
}
