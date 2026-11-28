<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\FinancialAdmissionToken;
use LogicException;
use Packman\Attributes\Internal;

/** Default binding: without a host admission service every financial mutation fails closed. */
#[Internal]
final class UnboundSessionAdmission implements FinancialSessionAdmission
{
    public function currentToken(string $installationRef): ?FinancialAdmissionToken
    {
        throw new LogicException('bind Billium\Core\Contracts\Runtime\FinancialSessionAdmission');
    }
}
