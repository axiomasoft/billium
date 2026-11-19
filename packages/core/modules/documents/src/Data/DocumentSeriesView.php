<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentSeriesView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $seriesRef,
        public DocumentType $documentType,
        public string $periodKey,
        public SeriesPeriodPolicy $periodPolicy,
        public string $timezone,
        public string $prefix,
        public int $padding,
        public int $legalEntityRevision,
        public int $nextNumber,
        public bool $active,
    ) {}
}
