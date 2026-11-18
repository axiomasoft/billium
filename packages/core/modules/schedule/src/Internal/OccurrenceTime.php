<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

/** Occurrence identity and persistence retain every microsecond (D12/D56; owner schedule). */
#[Internal]
final class OccurrenceTime
{
    public static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
