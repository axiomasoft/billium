<?php

declare(strict_types=1);

namespace Billium\Server\Contracts;

use Billium\Server\Data\AuthenticatedPrincipal;
use Billium\Server\Data\ExecutionContext;
use Billium\Server\Data\ExternalUsageAttribution;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Resolve the authorized event-time host mapping. Null means no authorized mapping. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface UsageAttributionResolver
{
    public function resolve(AuthenticatedPrincipal $principal, ExecutionContext $context, string $sourceRef, string $subjectRef, DateTimeImmutable $eventAt): ?ExternalUsageAttribution;
}
