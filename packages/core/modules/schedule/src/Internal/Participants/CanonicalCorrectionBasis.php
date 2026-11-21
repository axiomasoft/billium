<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Participants;

use Billium\Schedule\Contracts\CorrectionBasisPolicy;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\CorrectionBasis;
use Override;
use Packman\Attributes\Internal;

/** Schedule's own binding when no booking module installs a policy: the basis is derived from the parent and revision. */
#[Internal]
final readonly class CanonicalCorrectionBasis implements CorrectionBasisPolicy
{
    #[Override]
    public function accepts(RegisterCorrectionChild $command): bool
    {
        return in_array($command->obligationBasisRef, [
            CorrectionBasis::derive($command->parentOccurrenceRef, $command->correctionRevision),
            CorrectionBasis::deriveLedger($command->predecessorRatedBasisRef, $command->parentOccurrenceRef, $command->correctionRevision),
            CorrectionBasis::deriveLedger('rating:'.$command->predecessorRatedBasisRef, $command->parentOccurrenceRef, $command->correctionRevision),
        ], true);
    }
}
