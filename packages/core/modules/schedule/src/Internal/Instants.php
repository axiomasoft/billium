<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use DateTimeImmutable;
use DateTimeZone;

final class Instants
{
    public static function utc(string $value): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format('Y-m-d\TH:i:s\Z') !== $value) {
            ScheduleProblems::refuse('invalid_utc_instant');
        }

        return $instant;
    }

    public static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
