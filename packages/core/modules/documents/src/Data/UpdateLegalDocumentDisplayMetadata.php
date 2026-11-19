<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class UpdateLegalDocumentDisplayMetadata
{
    /** @param array<string, mixed> $displayMetadata */
    public function __construct(
        public string $commandKey,
        public DocumentScope $scope,
        public string $legalDocumentRef,
        public array $displayMetadata,
    ) {}
}
