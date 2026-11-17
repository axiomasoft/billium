<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\SourceAuthorityGrant;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host transport authentication at Tx0; payload selectors never establish source authority. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface IncomingCommandAuthenticator
{
    /**
     * Verify transport credentials and the configured execution owner, audience, source scope and lineage.
     * Pin qualified or legacy mode to authenticated credentials. For the qualified route verify a detached signature,
     * issuer, audience, expiry, original frozen owner and producer lineage against an independent origin journal.
     * Refuse a missing attestation on that route. Never manufacture a grant from an unsigned message or restored database row.
     *
     * @param  array<string, mixed>  $message
     */
    public function authenticate(string $destinationRef, array $message): SourceAuthorityGrant;
}
