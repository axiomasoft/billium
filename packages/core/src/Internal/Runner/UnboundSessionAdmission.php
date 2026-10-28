<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Data\FinancialAdmissionToken;
use LogicException;

/** Default binding: without a host admission service every financial mutation fails closed (D62). */
final class UnboundSessionAdmission implements FinancialSessionAdmission
{
    public function currentToken(string $installationRef): ?FinancialAdmissionToken
    {
        throw new LogicException('bind Billium\Core\Contracts\FinancialSessionAdmission');
    }
}
