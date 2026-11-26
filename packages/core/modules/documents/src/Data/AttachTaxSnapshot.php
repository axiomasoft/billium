<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class AttachTaxSnapshot
{
    public function __construct(
        public DocumentScope $scope,
        public string $finalizationRef,
        public int $finalizationRevision,
        public string $inputDigest,
        public DocumentTaxSnapshot $snapshot,
    ) {}
}
