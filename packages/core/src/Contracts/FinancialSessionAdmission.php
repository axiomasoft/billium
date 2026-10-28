<?php

declare(strict_types=1);

namespace Billium\Core\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Data\FinancialAdmissionToken;

/**
 * Host SPI for every financial profile, ledger-only/embedded-commerz included (D62). The host authority (a local
 * service durable outside the copied database is enough) authenticates the short-lived process token of the current
 * PHP/queue process and returns its verified claims; null means this process holds no valid token, and every financial
 * mutation refuses. Credentials and token values never enter the Billium database. Billium binds a default that
 * throws, so a missing binding fails closed.
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface FinancialSessionAdmission
{
    public function currentToken(string $installationRef): ?FinancialAdmissionToken;
}
