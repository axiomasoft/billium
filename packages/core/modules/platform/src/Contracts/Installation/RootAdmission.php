<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Installation;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Platform barriers the root runner applies after the idempotency claim, inside the same root Tx and through the root
 * LockSet. Each method requires an active context; none is a substitute for the runner.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface RootAdmission
{
    /**
     * Rank 15: billium_financial_session FOR SHARE, held to commit. A financial mode also verifies the host process
     * token against the adopted session and returns its epoch; Administrative returns the current epoch or null.
     *
     * @throws ProblemException installation.quarantined (no/invalid token) or recovery.required
     */
    public function admitSession(TransactionContext $context): ?int;

    /**
     * Rank 25: every module of the declared closure FOR SHARE in module_id order, validated against its descriptor,
     * dependency closure, schema write range and the operation class.
     *
     * @return array<string, int> module id => generation
     *
     * @throws ModuleAdmissionRefused
     */
    public function admitModules(TransactionContext $context): array;

    /** Appends the diagnostic money_commit_log row of a monetary root Tx; returns its commit_no (never a freshness proof). */
    public function appendCommit(TransactionContext $context, string $kind): int;
}
