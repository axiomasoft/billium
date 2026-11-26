<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Identity;

use Billium\Schedule\Internal\Canonical;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final class OccurrenceIdentity
{
    public static function ref(string $seller, string $env, string $component, string $purpose, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return 'occ_'.substr(hash('sha256', Canonical::encode([
            'seller' => $seller, 'environment' => $env, 'component' => $component, 'purpose' => $purpose,
            'startMicros' => self::micros($start), 'endMicros' => self::micros($end),
        ])), 0, 48);
    }

    private static function micros(DateTimeImmutable $instant): int
    {
        return ((int) $instant->format('U')) * 1_000_000 + (int) $instant->format('u');
    }
}
