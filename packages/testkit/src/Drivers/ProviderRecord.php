<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Enums\EffectKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderRecord
{
    /** @param array<string,int> $effectAmounts Amounts accepted for settlement, applied once on success. */
    public function __construct(
        public string $identity,
        public EffectKind $kind,
        public DriverRequest $request,
        public DateTimeImmutable $acceptedAt,
        public int $pid,
        public string $status,
        public ?string $providerOperationId = null,
        public array $effectAmounts = [],
    ) {}
}
