<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Ledger\Data\Obligations\CorrectionBasis;
use Billium\Schedule\Contracts\CorrectionBasisPolicy;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Override;
use Packman\Attributes\Internal;

/**
 * Rating books a correction as a linked ledger obligation, so the schedule child must bind that same basis: ledger's
 * correction derivation over the rated predecessor, namespaced so it can never collide with a schedule-derived ref.
 */
#[Internal]
final readonly class RatedCorrectionBasis implements CorrectionBasisPolicy
{
    public static function derive(string $predecessorBasisRef, string $occurrenceRef, int $correctionRevision): string
    {
        return CorrectionBasis::derive(originalBasisRef: 'rating:'.$predecessorBasisRef, correctionRef: $occurrenceRef,
            revision: $correctionRevision);
    }

    #[Override]
    public function accepts(RegisterCorrectionChild $command): bool
    {
        if ($command->predecessorRatedBasisRef === '' || $command->parentOccurrenceRef === '' || $command->correctionRevision < 1) {
            return false;
        }

        return $command->obligationBasisRef === self::derive(predecessorBasisRef: $command->predecessorRatedBasisRef,
            occurrenceRef: $command->parentOccurrenceRef, correctionRevision: $command->correctionRevision);
    }
}
