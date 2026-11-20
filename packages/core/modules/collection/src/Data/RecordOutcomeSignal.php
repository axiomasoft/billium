<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionSignalKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecordOutcomeSignal
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $caseRef,
        public CollectionSignalKind $signalKind,
        public string $signalDedupeKey,
        public DateTimeImmutable $effectiveAt,
        public array $payload = [],
    ) {}
}
