<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\ApplyManualIntervention;
use Billium\Collection\Data\ManualInterventionReceipt;
use Packman\Attributes\Api;

/**
 * Privileged seller-scoped manual intervention port with CAS revision, non-empty reason,
 * recovery proof, and durable audit repair receipts. Cannot forge payment success or clear unknown outcomes.
 */
#[Api(since: '1.0')]
interface CollectionManualInterventionService
{
    public const string PRIVILEGE_MANUAL_OVERRIDE = 'collection.manual_override';

    public function applyOverride(ApplyManualIntervention $command): ManualInterventionReceipt;
}
