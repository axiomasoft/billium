<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RecordDocumentWriteOff
{
    public function __construct(
        public string $commandKey,
        public DocumentScope $scope,
        public string $correctionRef,
        public string $originalLegalDocumentRef,
        public int $amountMinor,
        public string $currency,
        public string $reason,
    ) {}
}
