<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\VerifiedProcessAdmission;
use Billium\Core\Data\Runtime\VerifiedSessionAuthorization;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host trust boundary for every financial profile. Acquire, authenticate and adopt evidence at transaction level zero.
 * These accessors ONLY read the already verified local cache: no network, file I/O, signature verification or refresh.
 * The external host journal binds each nonce to one immutable transition/request; retries reuse that authorization.
 * Before caching activation, verify complete applicable provider coverage, account-to-owner namespace mapping,
 * unresolved dispositions and retirement/egress proof against the independent host journal.
 * Missing/retired cache entries return null. Neither DTO construction nor copied database state issues authority.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FinancialAuthorityEvidence
{
    public function process(string $installationRef, string $processRef, string $nonce): ?VerifiedProcessAdmission;

    public function transition(string $installationRef, string $transitionRef): ?VerifiedSessionAuthorization;
}
