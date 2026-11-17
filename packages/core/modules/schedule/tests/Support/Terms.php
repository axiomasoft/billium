<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Support;

use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Data\ScheduleRevisionTerms;

final class Terms
{
    /** @param array<string, mixed> $overrides */
    public static function make(array $overrides = []): ScheduleRevisionTerms
    {
        return FixtureArguments::construct(class: ScheduleRevisionTerms::class, arguments: ['sellerRef' => 'seller-a', 'environment' => 'test', 'agreementRef' => 'agreement-a', 'componentRef' => 'component-a', 'agreementRevision' => 1, 'cancellationGeneration' => 0, 'anchorLocal' => '2024-01-31T00:00:00', 'policy' => new PeriodPolicy(timezone: 'Etc/UTC', frequency: 'month'), 'priceVersionRef' => 'price:1', 'monetaryTermsDigest' => 'sha256:'.str_repeat('a', 64), 'externalCorrelationRef' => 'host:component-a', 'fundingRevision' => 1, ...$overrides]);
    }
}
