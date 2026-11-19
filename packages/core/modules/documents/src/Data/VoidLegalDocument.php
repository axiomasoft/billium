<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class VoidLegalDocument
{
    public function __construct(
        public string $commandKey,
        public DocumentScope $scope,
        public string $legalDocumentRef,
        public string $reason,
    ) {}
}
