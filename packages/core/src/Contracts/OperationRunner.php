<?php

declare(strict_types=1);

namespace Billium\Core\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\Receipt;
use Billium\Core\Data\StoredOutcome;
use Closure;

/**
 * Root pipeline of every money mutation (M-55, operation-runner). Requires transaction level 0. Order inside one READ
 * COMMITTED root Tx: authenticate caller scope → idempotency claim (first write, D51) → financial session FOR SHARE and
 * host process token (D54/D62) → module admission FOR SHARE (rank 25, D55) → SAVEPOINT body → complete claim → money
 * commit log → commit. A stored business refusal rolls back to the savepoint and commits only its receipt; a
 * none-policy error rolls back everything, the claim included (D31). 40001/40P01/LockPlanChanged retry the whole
 * database work within the budget with a new TransactionContext; network effects never run inside.
 */
#[Api]
#[Spi(SpiKind::Call)]
interface OperationRunner
{
    /**
     * @param  Closure(TransactionContext): Receipt  $body
     *
     * @throws ProblemException a none-policy refusal (nothing stored)
     */
    public function run(OperationSpec $spec, Closure $body): StoredOutcome;

    /**
     * Accepts only the live context this runner minted for its current root attempt and, with `$moduleId`, only for a
     * module of the admitted closure. Every typed participant calls this before it writes (D60).
     *
     * @throws \LogicException stale, foreign or forged context, or a module outside the closure
     */
    public function assertCurrent(TransactionContext $context, ?string $moduleId = null): void;
}
