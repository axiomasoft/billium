<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\RegisterDocumentProfile;
use Billium\Documents\Enums\DocumentType;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DocumentProfileRegistry
{
    public function register(RegisterDocumentProfile $command): DocumentProfile;

    public function find(DocumentScope $scope, string $profileRef, int $version): ?DocumentProfile;

    public function requireComplete(DocumentScope $scope, string $profileRef, int $version, DocumentType $documentType): DocumentProfile;
}
