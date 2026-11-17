<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\CollectionGuardPlan;
use Billium\Execution\Data\CollectionGuardReceipt;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\ExecutionPhase;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Plans and validates current collection consent/revocation in the current root before RootCharge locks.
 * Missing host binding must refuse collection-origin effects. No request-selected callback may bypass this port.
 * markDispatch and attempt CAS commit together; a failed CAS rolls the dispatch marker back.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface CollectionExecutionGuard
{
    public function plan(string $authorizationRef, ExecutionPhase $phase, ExecutionScope $scope): CollectionGuardPlan;

    public function validate(TransactionContext $ctx, CollectionGuardPlan $plan, string $operationRef, string $businessDigest): CollectionGuardReceipt;

    public function markDispatch(TransactionContext $ctx, CollectionGuardPlan $plan, DispatchDecision $decision): string;
}
