<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Packman\Attributes\Internal;

#[Internal]
final readonly class UnboundFinancialAuthorityEvidence implements FinancialAuthorityEvidence
{
    public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission
    {
        return null;
    }

    public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization
    {
        return null;
    }
}
