<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Unit;

use Billium\Schedule\Internal\Identity\OccurrenceIdentity;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OccurrenceIdentityValueTest extends TestCase
{
    public function test_identity_normalizes_offsets_and_retains_microseconds(): void
    {
        $make = static fn (string $start): string => OccurrenceIdentity::ref(seller: 'seller-a', env: 'test',
            component: 'component-a', purpose: 'recurring_charge', start: new DateTimeImmutable($start),
            end: new DateTimeImmutable('2024-02-01T00:00:00Z'));
        self::assertSame($make('2024-01-01T00:00:00Z'), $make('2024-01-01T04:00:00+04:00'));
        self::assertNotSame($make('2024-01-01T00:00:00Z'), $make('2024-01-01T00:00:00.000001Z'));
        self::assertMatchesRegularExpression('/^occ_[0-9a-f]{48}$/', $make('2024-01-01T00:00:00Z'));
    }
}
