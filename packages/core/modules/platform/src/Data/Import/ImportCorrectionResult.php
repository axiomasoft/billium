<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use Packman\Attributes\Api;

/**
 * Result of applying a post-import linked correction without overwriting original records or triggering PSP side effects.
 */
#[Api(since: '1.0')]
final readonly class ImportCorrectionResult
{
    /**
     * @param  list<array{componentRef: string, obligationRef: string, amount: array{amount: string, currency: string}}>  $allocatedComponents
     */
    public function __construct(
        public string $correctionRef,
        public string $kind,
        public string $originalSourceFactRef,
        public string $correctionSourceFactRef,
        public ImportMappingRecord $mapping,
        public ?string $billiumReceiptRef = null,
        public ?Money $totalDelta = null,
        public array $allocatedComponents = [],
        public bool $externalProviderActionDispatched = false,
    ) {}
}
