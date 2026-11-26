<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class VoidAndReissueLegalDocument
{
    public function __construct(
        public string $commandKey,
        public DocumentScope $scope,
        public string $correctionRef,
        public string $originalLegalDocumentRef,
        public string $newFinalizationRef,
        public string $approvedSnapshotDigest,
        public string $seriesRef,
        public string $periodKey,
        public string $reason,
    ) {}
}
