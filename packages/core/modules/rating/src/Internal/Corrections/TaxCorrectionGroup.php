<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Corrections;

use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Internal\Canonical\CanonicalJson;

final class TaxCorrectionGroup
{
    /** @param list<TaxSnapshot> $snapshots */
    public static function isHomogeneous(array $snapshots): bool
    {
        if ($snapshots === []) {
            return false;
        }
        $first = CanonicalJson::encode($snapshots[0]);
        foreach ($snapshots as $snapshot) {
            if (CanonicalJson::encode($snapshot) !== $first) {
                return false;
            }
        }

        return true;
    }
}
