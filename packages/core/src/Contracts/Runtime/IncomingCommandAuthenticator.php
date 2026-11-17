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
     * Never manufacture a grant from an unsigned message or restored database row.
     *
     * @param  array<string, mixed>  $message
     */
    public function authenticate(string $destinationRef, array $message): SourceAuthorityGrant;
}
