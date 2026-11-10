<?php

declare(strict_types=1);

namespace Billium\Schedule\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Contracts\Codec;

#[Api]
final class CorrectionBasis
{
    public static function derive(string $parentOccurrenceRef, int $correctionRevision): string
    {
        return 'correction:'.hash('sha256', $parentOccurrenceRef.'#'.$correctionRevision);
    }

    public static function deriveLedger(string $originalBasisRef, string $parentOccurrenceRef, int $correctionRevision): string
    {
        return 'correction:'.hash('sha256', (new Codec)->canonicalize([
            'original_basis_ref' => $originalBasisRef,
            'correction_ref' => $parentOccurrenceRef,
            'revision' => $correctionRevision,
        ]));
    }
}
