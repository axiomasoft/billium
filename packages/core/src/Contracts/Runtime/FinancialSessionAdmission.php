<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host SPI for every financial profile, ledger-only/embedded-commerz included. The host authority (a local
 * service durable outside the copied database is enough) authenticates the short-lived process token of the current
 * PHP/queue process and returns its verified claims; null means this process holds no valid token, and every financial
 * mutation refuses. This accessor only reads a verified local cache; acquisition and authentication occur at Tx0.
 * Matching FinancialAuthorityEvidence is mandatory; returning a constructed DTO alone grants no authority. Credentials and token values never enter the Billium database. Billium binds a default that
 * throws, so a missing binding fails closed.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FinancialSessionAdmission
{
    public function currentToken(string $installationRef): ?FinancialAdmissionToken;
}
