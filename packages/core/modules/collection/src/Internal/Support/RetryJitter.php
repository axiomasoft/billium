<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Support;

use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final class RetryJitter
{
    public static function seconds(string $caseRef, string $stepRef, DateTimeImmutable $occurredAt, int $jitterMaxSeconds): int
    {
        if ($jitterMaxSeconds < 1) {
            return 0;
        }
        $material = $caseRef."\0".$stepRef."\0".RowCast::formatUtc($occurredAt);
        $slice = substr(hash('sha256', $material), 0, 8);

        return ((int) hexdec($slice)) % ($jitterMaxSeconds + 1);
    }
}
