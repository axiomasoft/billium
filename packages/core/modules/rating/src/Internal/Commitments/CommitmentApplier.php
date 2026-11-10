<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Commitments;

use Billium\Rating\Internal\Math\Dec;

final class CommitmentApplier
{
    public static function apply(Dec $raw, int $exponent, ?int $minimumMinor, ?int $maximumMinor, string $alreadyBilledNetMinor): Dec
    {
        $already = Dec::ofUnscaled(unscaled: $alreadyBilledNetMinor, scale: $exponent);
        $result = $raw;
        if ($minimumMinor !== null) {
            $result = $result->max(Dec::ofUnscaled(unscaled: (string) $minimumMinor, scale: $exponent)->sub($already));
        }
        if ($maximumMinor !== null) {
            $result = $result->min(Dec::ofUnscaled(unscaled: (string) $maximumMinor, scale: $exponent)->sub($already));
        }

        return $result->max(Dec::parse('0'));
    }
}
