<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Exceptions\ModuleAdmissionRefused;

/**
 * Platform barriers the root runner applies after the idempotency claim, inside the same root Tx and through the root
 * LockSet. Each method requires an active context; none is a substitute for the runner.
 */
#[Api]
#[Spi(SpiKind::Call)]
interface RootAdmission
{
    /**
     * Rank 15: billium_financial_session FOR SHARE, held to commit. A financial mode also verifies the host process
     * token against the adopted session (D62) and returns its epoch; Administrative returns the current epoch or null.
     *
     * @throws ProblemException installation.quarantined (no/invalid token) or recovery.required
     */
    public function admitSession(TransactionContext $context): ?int;

    /**
     * Rank 25: every module of the declared closure FOR SHARE in module_id order, validated against its descriptor,
     * dependency closure, schema write range and the operation class (D55).
     *
     * @return array<string, int> module id => generation
     *
     * @throws ModuleAdmissionRefused
     */
    public function admitModules(TransactionContext $context): array;

    /** Appends the diagnostic money_commit_log row of a monetary root Tx; returns its commit_no (never a freshness proof). */
    public function appendCommit(TransactionContext $context, string $kind): int;
}
