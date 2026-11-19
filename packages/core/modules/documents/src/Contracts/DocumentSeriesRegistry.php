<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\CreateDocumentSeries;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentSeriesView;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DocumentSeriesRegistry
{
    public function createSeries(CreateDocumentSeries $command): DocumentSeriesView;

    public function findSeries(DocumentScope $scope, string $seriesRef, string $periodKey): ?DocumentSeriesView;
}
