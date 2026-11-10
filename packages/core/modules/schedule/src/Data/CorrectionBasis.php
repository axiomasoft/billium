<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final class CorrectionBasis
{
    public static function derive(string $parentOccurrenceRef, int $correctionRevision): string
    {
        return 'correction:'.hash('sha256', $parentOccurrenceRef.'#'.$correctionRevision);
    }
}
