<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\ExecutionScope;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Deployment-owned archive of the independent original dispatch manifest and authenticated commercial source journal. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface OriginalIdentityArchive
{
    /** Authenticate both original records at Tx0 for this exact account, reference, current recovery owner, epoch and process token. */
    public function authenticate(ExecutionScope $scope, string $account, string $reference, FinancialAdmissionToken $token, int $epoch): ?ArchivedExecutionIdentity;

    /** In-memory read only. Return the exact object authenticated for these claims while its current host authorization remains valid. */
    public function cached(ExecutionScope $scope, string $account, string $reference, FinancialAdmissionToken $token, int $epoch): ?ArchivedExecutionIdentity;
}
