<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Obligations;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

/**
 * D63/F01: a positive late correction of a finalized obligation is a separate linked obligation. Its basis is derived
 * from the original basis and the correction identity/revision, so the same correction replays and never overwrites
 * the original (LedgerParticipants::recordObligation).
 */
#[Api(since: '1.0')]
final class CorrectionBasis
{
    public static function derive(string $originalBasisRef, string $correctionRef, int $revision): string
    {
        if ($originalBasisRef === '' || $correctionRef === '' || $revision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'a correction names its original basis, identity and revision');
        }

        return 'correction:'.hash('sha256', (new Codec)->canonicalize([
            'original_basis_ref' => $originalBasisRef, 'correction_ref' => $correctionRef, 'revision' => $revision,
        ]));
    }
}
