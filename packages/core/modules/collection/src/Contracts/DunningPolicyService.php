<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\RecurringOwnerView;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RetireRecurringOwner;
use Packman\Attributes\Api;

/**
 * Manages immutable DunningPolicy versions and enforces single recurring owner per agreement component.
 */
#[Api(since: '1.0')]
interface DunningPolicyService
{
    /**
     * Persists a new immutable DunningPolicy version without altering any existing dunning_case.
     */
    public function registerPolicyVersion(RegisterDunningPolicyVersion $command): DunningPolicyView;

    /**
     * Registers the single active recurring owner for an agreement component.
     * Deterministically rejects enabling provider autonomous subscription alongside Billium scheduler.
     */
    public function registerRecurringOwner(RegisterRecurringOwner $command): RecurringOwnerView;

    /**
     * Retires an active recurring owner with expected revision CAS prior to any ownership handover.
     */
    public function retireRecurringOwner(RetireRecurringOwner $command): RecurringOwnerView;

    /**
     * Asserts that Billium scheduler / dunning is permitted for the given component (no active provider autonomous subscription).
     */
    public function assertBilliumSchedulerAllowed(CollectionScope $scope, string $componentRef): void;
}
