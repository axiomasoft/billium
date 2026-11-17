<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Corrections;

use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Packman\Attributes\Internal;

#[Internal]
final class TaxCorrectionGroup
{
    /** @param list<TaxSnapshot> $snapshots */
    public static function isHomogeneous(array $snapshots): bool
    {
        if ($snapshots === []) {
            return false;
        }
        $first = CanonicalJson::encode($snapshots[0]);

        return array_all($snapshots, fn ($snapshot): bool => CanonicalJson::encode($snapshot) === $first);
    }
}
